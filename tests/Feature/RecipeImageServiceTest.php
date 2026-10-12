<?php

namespace Tests\Feature;

use App\Services\RecipeImageService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\GeminiFixture;
use Tests\Support\UnsplashFixture;
use Tests\TestCase;

class RecipeImageServiceTest extends TestCase
{
    use GeminiFixture, UnsplashFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.unsplash.access_key' => 'test-unsplash-secret']);
        Cache::flush();
        Http::preventStrayRequests();
    }

    public function test_valid_relevant_photo_is_selected_not_merely_first_result_and_cache_hit_makes_no_request(): void
    {
        $this->fakeProvider(['api.unsplash.com/*' => Http::response(['results' => [$this->photo('A forest'), $this->photo()]], 200,
            ['X-Ratelimit-Remaining' => '49', 'X-Ratelimit-Limit' => '50'])]);
        $service = app(RecipeImageService::class);
        $image = $service->getRecipeImage('Nasi Goreng Telur');
        $this->assertSame('unsplash', $image['image_source']);
        $this->assertSame('test-photo', $image['unsplash_photo_id']);
        $this->assertSame($this->photo()['urls']['regular'], $image['image_url']);
        $this->assertStringContainsString('existing=1&utm_source=dapur_rantau&utm_medium=referral', $image['photographer_url']);
        $this->assertSame($image, $service->getRecipeImage(' NASI   GORENG TELUR '));
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Client-ID test-unsplash-secret')
            && $request->hasHeader('Accept-Version', 'v1') && $request['query'] === 'nasi goreng telur'
            && $request['per_page'] === 5 && $request['orientation'] === 'landscape'
            && $request['order_by'] === 'relevant' && $request['content_filter'] === 'high'
            && ! str_contains($request->url(), 'test-unsplash-secret'));
        $this->travel(7)->days();
        $this->travel(1)->seconds();
        $service->getRecipeImage('nasi goreng telur');
        Http::assertSentCount(2);
    }

    public function test_separated_soup_words_from_live_metadata_are_accepted_but_noodle_soup_is_rejected(): void
    {
        $this->fakeProvider(['*' => Http::response(['results' => [
            $this->photo('A bowl of vietnamese noodle soup with tofu and herbs', 'noodles'),
            $this->photo('Seaweed soup and porridge served on a plaid tablecloth', 'unrelated'),
            $this->photo('Bowl of miso soup with tofu and seaweed', 'tofu'),
        ]])]);
        $service = app(RecipeImageService::class);
        $image = $service->getRecipeImage('Sup Segar Jagung Wortel Tahu');
        $this->assertSame('tofu', $image['unsplash_photo_id']);
        $this->assertSame($image, $service->getRecipeImage('Sup Segar Jagung Wortel Tahu'));
        Http::assertSentCount(1);
        $this->assertSame('tofu soup corn carrot', $service->buildSearchQuery('Sup Segar Jagung Wortel Tahu'));
        $this->assertSame('clear vegetable soup corn spinach', $service->buildSearchQuery('Sayur Bening Bayam Jagung'));
        $this->assertSame('stir fried tofu carrot', $service->buildSearchQuery('Tumis Tahu Wortel Pedas'));
    }

    public function test_live_unrelated_vegetable_results_remain_placeholders(): void
    {
        foreach ([
            'Sayur Bening Bayam Jagung' => ['Fried fish with corn and greens', 'green corn plant during daytime'],
            'Tumis Tahu Wortel Pedas' => ['Tahu Gejrot. Fried tofu with sweet and spicy Sambal.', 'A fruit salad with mint garnish'],
        ] as $title => $metadata) {
            $this->fakeProvider(['*' => Http::response(['results' => array_map(fn ($text) => $this->photo($text), $metadata)])]);
            $this->assertSame('placeholder', app(RecipeImageService::class)->getRecipeImage($title)['image_source']);
        }
    }

    public function test_recipe_title_variants_use_translated_dish_queries_and_relevant_metadata(): void
    {
        $cases = [
            ['Nasi Goreng Seafood Merdeka', 'Indonesian fried rice seafood', 'Seafood fried rice'],
            ['Omelet Tahu Bayam', 'Indonesian omelette tofu spinach', 'Spinach tofu omelette'],
            ['Sup Tahu Bayam Telur', 'tofu soup spinach egg', 'Tofu soup with spinach'],
            ['Sup Segar Cumi Udang', 'seafood soup shrimp squid', 'Seafood soup with squid'],
            ['Telur Dadar Gulung Seafood', 'Indonesian omelette seafood', 'Seafood omelette'],
            ['Mi Goreng Praktis', 'Indonesian fried noodles', 'Mi goreng Jawa'],
        ];
        $service = app(RecipeImageService::class);
        foreach ($cases as [$title, $query, $metadata]) {
            Cache::flush();
            $this->assertSame($query, $service->buildSearchQuery($title));
            $this->fakeProvider(['api.unsplash.com/*' => Http::response(['results' => [$this->photo($metadata)]])]);
            $image = $service->getRecipeImage($title);
            $this->assertSame('unsplash', $image['image_source']);
            $this->assertSame($image, $service->getRecipeImage($title));
            Http::assertSent(fn ($request) => $request['query'] === $service->buildSearchQueries($title)[0]);
            Http::assertSentCount(1);
        }
    }

    public function test_new_dish_aliases_still_reject_unrelated_photos(): void
    {
        foreach (['Omelet Tahu Bayam', 'Sup Tahu Bayam Telur', 'Sup Segar Cumi Udang'] as $title) {
            $this->fakeProvider(['*' => Http::response(['results' => [$this->photo('A plate of pasta and salad')]])]);
            $this->assertSame('placeholder', app(RecipeImageService::class)->getRecipeImage($title)['image_source']);
        }
        $soup = $this->photo('Tofu omelette');
        $soup['alt_description'] = 'A bowl of soup';
        Cache::flush();
        $this->fakeProvider(['*' => Http::response(['results' => [$soup]])]);
        $this->assertSame('placeholder', app(RecipeImageService::class)->getRecipeImage('Omelet Tahu Bayam')['image_source']);
    }

    public function test_reordered_words_and_dish_variants_rank_specific_photo_above_generic_photo(): void
    {
        $this->fakeProvider(['*' => Http::response(['results' => [
            $this->photo('A bowl of fried rice', 'generic'),
            $this->photo('Fried rice with egg on a plate', 'specific'),
            $this->photo('A plate of food', 'unrelated'),
        ]])]);
        $image = app(RecipeImageService::class)->getRecipeImage('Nasi Goreng Telur Praktis');
        $this->assertSame('specific', $image['unsplash_photo_id']);
        $this->assertSame('unsplash', $image['image_source']);
    }

    public function test_dish_identity_is_required_and_keyword_substrings_are_rejected(): void
    {
        foreach (['A plate of food', 'Rice and fried meat with egg in plate', 'A fried ricecake', 'Eggplant with rice', 'Raw rice in a bag'] as $metadata) {
            Cache::flush();
            $this->fakeProvider(['*' => Http::response(['results' => [$this->photo($metadata)]])]);
            $this->assertSame('placeholder', app(RecipeImageService::class)->getRecipeImage('Nasi Goreng Telur')['image_source']);
        }
    }

    public function test_old_negative_cache_cannot_hide_results_after_strategy_upgrade(): void
    {
        $service = app(RecipeImageService::class);
        $oldKey = 'recipe-image:1:'.hash('sha256', 'nasi goreng telur');
        Cache::put($oldKey, $service->getPlaceholder(), 3600);
        $this->fakeProvider(['*' => Http::response(['results' => [$this->photo('Fried rice with egg')]])]);
        $this->assertNotSame($oldKey, $service->cacheKey('nasi goreng telur'));
        $image = $service->getRecipeImage('nasi goreng telur');
        $this->assertSame('unsplash', $image['image_source']);
        $this->assertSame($image, $service->getRecipeImage(' NASI  GORENG TELUR '));
        $this->assertSame('placeholder', Cache::get($oldKey)['image_source']);
        Http::assertSentCount(1);
    }

    public function test_live_mi_spelling_is_accepted_but_generic_noodle_metadata_is_not(): void
    {
        $this->fakeProvider(['*' => Http::response(['results' => [
            $this->photo('INDONESIAN NOODLE', 'generic'),
            $this->photo('Mi Goreng Jawa, Indonesian Food', 'mi-goreng'),
        ]])]);
        $this->assertSame('mi-goreng', app(RecipeImageService::class)->getRecipeImage('Mie Goreng')['unsplash_photo_id']);
    }

    public function test_conflicting_omelette_metadata_is_rejected_and_visual_confirmation_is_preferred(): void
    {
        $soup = $this->photo('Omelette with cheese', 'soup');
        $soup['alt_description'] = 'a bowl of soup';
        $quiche = $this->photo('Omelette', 'quiche');
        $quiche['alt_description'] = 'a slice of quiche on a white plate';
        $confirmed = $this->photo('A Sri Lankan style omlette.', 'confirmed');
        $confirmed['alt_description'] = 'a white plate topped with an omelet next to a glass of orange juice';
        $this->fakeProvider(['*' => Http::response(['results' => [$soup, $quiche, $confirmed]])]);
        $this->assertSame('confirmed', app(RecipeImageService::class)->getRecipeImage('Telur Dadar')['unsplash_photo_id']);
        Cache::flush();
        $this->fakeProvider(['*' => Http::response(['results' => [$soup, $quiche]])]);
        $this->assertSame('placeholder', app(RecipeImageService::class)->getRecipeImage('Telur Dadar')['image_source']);
    }

    public function test_empty_or_irrelevant_results_are_negative_cached_for_one_hour(): void
    {
        foreach ([[], [$this->photo('A forest')], [array_replace($this->photo(), ['description' => null, 'alt_description' => null])]] as $photos) {
            Cache::flush();
            $this->fakeProvider(['*' => Http::response(['results' => $photos])]);
            $service = app(RecipeImageService::class);
            $this->assertSame('placeholder', $service->getRecipeImage('nasi goreng telur')['image_source']);
            $service->getRecipeImage('nasi goreng telur');
            Http::assertSentCount(3);
            $this->travel(3601)->seconds();
            $service->getRecipeImage('nasi goreng telur');
            Http::assertSentCount(6);
        }
    }

    public function test_missing_key_and_invalid_base_url_never_send_http(): void
    {
        $this->fakeProvider([]);
        config(['services.unsplash.access_key' => '']);
        $this->assertSame('placeholder', app(RecipeImageService::class)->getRecipeImage('telur')['image_source']);
        config(['services.unsplash.access_key' => 'test-only', 'services.unsplash.base_url' => 'https://example.com']);
        app(RecipeImageService::class)->getRecipeImage('telur');
        Http::assertNothingSent();
    }

    public function test_provider_errors_and_timeout_do_not_retry_or_cache_a_photo(): void
    {
        foreach ([400, 401, 403, 429, 500, 503, 'timeout', 'invalid-json', 'empty'] as $error) {
            Cache::flush();
            $calls = 0;
            $this->fakeProvider(function () use ($error, &$calls) {
                $calls++;
                if ($error === 'timeout') {
                    throw new ConnectionException('test-unsplash-secret');
                }

                return Http::response(is_int($error) ? [] : ($error === 'empty' ? '' : '{broken'), is_int($error) ? $error : 200);
            });
            $service = app(RecipeImageService::class);
            $this->assertSame('placeholder', $service->getRecipeImage('nasi goreng telur')['image_source']);
            $this->assertSame('placeholder', Cache::get($service->cacheKey('nasi goreng telur'))['image_source']);
            $this->assertSame('placeholder', $service->getRecipeImage('nasi goreng telur')['image_source']);
            $this->assertSame(1, $calls);
        }
    }

    public function test_invalid_urls_and_missing_attribution_are_rejected(): void
    {
        foreach (['javascript:alert(1)', 'https://images.unsplash.com.evil.test/photo', 'http://images.unsplash.com/photo', 'https://user@images.unsplash.com/photo'] as $url) {
            Cache::flush();
            $photo = $this->photo();
            $photo['urls']['regular'] = $url;
            $this->fakeProvider(['*' => Http::response(['results' => [$photo]])]);
            $this->assertSame('placeholder', app(RecipeImageService::class)->getRecipeImage('nasi goreng telur')['image_source']);
        }
        Cache::flush();
        $photo = $this->photo();
        $photo['user']['name'] = null;
        $this->fakeProvider(['*' => Http::response(['results' => [$photo]])]);
        $this->assertSame('placeholder', app(RecipeImageService::class)->getRecipeImage('nasi goreng telur')['image_source']);
    }

    public function test_low_quota_uses_cached_photos_but_skips_new_searches(): void
    {
        $this->fakeProvider(['*' => Http::response(['results' => [$this->photo()]], 200, ['X-Ratelimit-Remaining' => '2'])]);
        $service = app(RecipeImageService::class);
        $image = $service->getRecipeImage('nasi goreng telur');
        $this->assertSame('unsplash', $image['image_source']);
        $this->assertSame($image, $service->getRecipeImage('nasi goreng telur'));
        $this->assertSame('placeholder', $service->getRecipeImage('ayam kecap')['image_source']);
        Http::assertSentCount(1);
    }

    public function test_global_lock_and_local_budget_prevent_excess_requests(): void
    {
        $this->fakeProvider([]);
        $scope = 'unsplash:'.hash('sha256', 'test-unsplash-secret');
        $lock = Cache::lock($scope.':lock', 20);
        $lock->get();
        $this->assertSame('placeholder', app(RecipeImageService::class)->getRecipeImage('telur')['image_source']);
        $lock->release();
        Cache::put($scope.':budget', 45, 3600);
        $this->assertSame('placeholder', app(RecipeImageService::class)->getRecipeImage('telur')['image_source']);
        Http::assertNothingSent();
    }

    public function test_query_mapping_preserves_unknown_recipe_titles_and_local_photo_has_priority(): void
    {
        $service = app(RecipeImageService::class);
        $this->assertSame('Indonesian fried noodles', $service->buildSearchQuery('Mie Goreng'));
        $this->assertSame('Indonesian omelette vegetable', $service->buildSearchQuery('Telur Dadar Sayur'));
        $this->assertSame('perkedel kentang', $service->buildSearchQuery('Perkedel Kentang'));
        $dir = public_path('images/recipes');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $path = $dir.'/test-curated.png';
        file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII='));
        try {
            config(['services.unsplash.local_images' => ['telur dadar' => '/images/recipes/test-curated.png']]);
            $this->fakeProvider([]);
            $this->assertSame('local', $service->getRecipeImage('Telur Dadar')['image_source']);
            Http::assertNothingSent();
        } finally {
            unlink($path);
        }
    }
}
