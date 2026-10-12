<?php

namespace Tests\Feature;

use App\Services\RecipeImageService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\GeminiFixture;
use Tests\Support\UnsplashFixture;
use Tests\TestCase;

class RecipeImageSearchOrderTest extends TestCase
{
    use GeminiFixture, UnsplashFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.unsplash.access_key' => 'test-only']);
        Cache::flush();
        Http::preventStrayRequests();
    }

    public function test_all_titles_have_bounded_specific_to_general_search_plans(): void
    {
        $service = app(RecipeImageService::class);
        $cases = [
            'Nasi Goreng Ayam Pedas' => ['nasi goreng ayam pedas', 'nasi goreng ayam', 'nasi goreng', 'fried rice'],
            'Mie Goreng Sayur' => ['mie goreng sayur', 'mie goreng', 'fried noodles'],
            'Sup Jagung Wortel' => ['sup jagung wortel', 'sup jagung', 'sup sayur', 'vegetable soup'],
            'Ayam Goreng Bawang Putih' => ['ayam goreng bawang putih', 'ayam goreng bawang', 'ayam goreng', 'fried chicken'],
            'Tumis Tahu Wortel Pedas' => ['tumis tahu wortel pedas', 'tumis tahu wortel', 'tumis tahu', 'tofu stir fry'],
            'Sup Segar Jagung Wortel Tahu' => ['sup segar jagung wortel tahu', 'sup tahu', 'tofu soup'],
            'Pepes Jamur Kemangi Pedas' => ['pepes jamur kemangi pedas', 'pepes jamur kemangi', 'pepes jamur'],
            'Rendang' => ['rendang'],
            'Gudeg' => ['gudeg'],
        ];
        foreach ($cases as $title => $expected) {
            $this->assertSame($expected, $service->buildSearchQueries($title));
            $this->assertLessThanOrEqual(5, count($expected));
        }
        $this->assertSame([], $service->buildSearchQueries('   '));
    }

    public function test_multiple_dish_families_reach_english_fallback_and_cache_the_result(): void
    {
        foreach ([
            ['Nasi Goreng Ayam Pedas', 'fried rice', 'Fried rice on a plate'],
            ['Mie Goreng Sayur', 'fried noodles', 'Fried noodles with vegetables'],
            ['Sup Jagung Wortel', 'vegetable soup', 'Vegetable soup'],
            ['Ayam Goreng Bawang Putih', 'fried chicken', 'Crispy fried chicken'],
            ['Tumis Tahu Wortel Pedas', 'tofu stir fry', 'Tofu stir fry'],
            ['Pepes Jamur Kemangi Pedas', 'pepes jamur', 'Pepes jamur served on a plate'],
        ] as [$title, $finalQuery, $description]) {
            Cache::flush();
            $seen = [];
            $this->fakeProvider(function ($request) use (&$seen, $finalQuery, $description) {
                $seen[] = $request['query'];

                return Http::response(['results' => $request['query'] === $finalQuery ? [$this->photo($description)] : [$this->photo('Raw vegetables in a basket')]]);
            });
            $service = app(RecipeImageService::class);
            $image = $service->getRecipeImage($title);
            $this->assertSame('unsplash', $image['image_source']);
            $this->assertSame($service->buildSearchQueries($title), $seen);
            $this->assertSame($image, $service->getRecipeImage($title));
            Http::assertSentCount(count($seen));
        }
    }

    public function test_family_cache_is_shared_only_with_the_same_dish_and_unknown_titles_stay_isolated(): void
    {
        $service = app(RecipeImageService::class);
        $this->fakeProvider(['*' => Http::response(['results' => [$this->photo('Fried rice')]])]);
        $first = $service->getRecipeImage('Nasi Goreng Ayam');
        $second = $service->getRecipeImage('Nasi Goreng Sayur');
        $this->assertSame('unsplash', $second['image_source']);
        $this->assertSame($first['image_url'], $second['image_url']);
        Http::assertSentCount(1);
        $this->fakeProvider(['*' => Http::response(['results' => [$this->photo('Fried rice')]])]);
        $this->assertSame('placeholder', $service->getRecipeImage('Mie Goreng Sayur')['image_source']);
        Http::assertSentCount(3);
        $this->fakeProvider(['*' => Http::response(['results' => [$this->photo('Pepes jamur')]])]);
        $service->getRecipeImage('Pepes Jamur Kemangi');
        $service->getRecipeImage('Pepes Jamur Pedas');
        Http::assertSentCount(2);
    }

    public function test_requested_order_stops_at_each_success_and_reuses_cache(): void
    {
        $title = 'Telur Dadar Wortel Tahu';
        $service = app(RecipeImageService::class);
        $queries = ['telur dadar wortel tahu', 'telur dadar wortel', 'telur dadar', 'vegetable omelette', 'omelette'];
        $this->assertSame($queries, $service->buildSearchQueries(' TELUR   DADAR WORTEL TAHU '));
        $this->assertSame(['telur dadar wortel', 'telur dadar', 'vegetable omelette', 'omelette'], $service->buildSearchQueries('Telur Dadar Wortel'));
        foreach ([1, 2, 3, 4, 5] as $successAt) {
            Cache::flush();
            $seen = [];
            $this->fakeProvider(function ($request) use (&$seen, $successAt) {
                $seen[] = $request['query'];

                return Http::response(['results' => count($seen) === $successAt ? [$this->photo('Carrot tofu omelette')] : [$this->photo('A forest')]]);
            });
            $image = $service->getRecipeImage($title);
            $this->assertSame('unsplash', $image['image_source']);
            $this->assertSame(array_slice($queries, 0, $successAt), $seen);
            $this->assertSame($image, $service->getRecipeImage($title));
            Http::assertSentCount($successAt);
        }
    }

    public function test_similar_omelette_cache_is_used_for_another_title_without_more_requests(): void
    {
        $this->fakeProvider(['*' => Http::response(['results' => [$this->photo('An omelette on a plate')]])]);
        $service = app(RecipeImageService::class);
        $first = $service->getRecipeImage('Telur Dadar Wortel Tahu');
        $second = $service->getRecipeImage('Telur Dadar Bayam');
        $this->assertSame($first['image_url'], $second['image_url']);
        $this->assertSame('unsplash', $second['image_source']);
        $this->assertStringContainsString('telur dadar bayam', $second['image_alt']);
        Http::assertSentCount(1);
        $this->fakeProvider(['*' => Http::response(['results' => []])]);
        $this->assertSame('placeholder', $service->getRecipeImage('Nasi Goreng')['image_source']);
        Http::assertSentCount(2);
    }

    public function test_all_empty_levels_produce_cached_placeholder(): void
    {
        $this->fakeProvider(['*' => Http::response(['results' => []])]);
        $service = app(RecipeImageService::class);
        $this->assertSame('placeholder', $service->getRecipeImage('Telur Dadar Wortel Tahu')['image_source']);
        $service->getRecipeImage('Telur Dadar Wortel Tahu');
        Http::assertSentCount(5);
    }

    public function test_provider_errors_and_invalid_json_do_not_broaden_search(): void
    {
        foreach ([400, 401, 403, 429, 500, 503, 200] as $status) {
            Cache::flush();
            $this->fakeProvider(['*' => Http::response('invalid', $status)]);
            $this->assertSame('placeholder', app(RecipeImageService::class)->getRecipeImage('Telur Dadar Wortel Tahu')['image_source']);
            Http::assertSentCount(1);
        }
    }

    public function test_remaining_budget_is_checked_before_each_level(): void
    {
        Cache::put('unsplash:'.hash('sha256', 'test-only').':budget', 44, 3600);
        $this->fakeProvider(['*' => Http::response(['results' => []])]);
        $this->assertSame('placeholder', app(RecipeImageService::class)->getRecipeImage('Telur Dadar Wortel Tahu')['image_source']);
        Http::assertSentCount(1);
    }

    public function test_only_unresolved_recipe_continues_without_reordering_images(): void
    {
        $seen = [];
        $this->fakeProvider(function ($request) use (&$seen) {
            $query = $request['query'];
            $seen[] = $query;
            $photo = match ($query) {
                'telur rebus' => $this->photo('Boiled eggs', 'egg'),
                'nasi goreng' => $this->photo('Fried rice', 'rice'),
                'telur dadar' => $this->photo('Omelette', 'omelette'),
                default => null,
            };

            return Http::response(['results' => $photo ? [$photo] : []]);
        });
        $images = app(RecipeImageService::class)->getImagesForRecipes([
            ['title' => 'Telur Rebus'], ['title' => 'Telur Dadar Wortel Tahu'], ['title' => 'Nasi Goreng'],
        ]);
        $this->assertSame(['egg', 'omelette', 'rice'], array_column($images, 'unsplash_photo_id'));
        $this->assertSame(1, count(array_filter($seen, fn ($q) => $q === 'telur rebus')));
        $this->assertSame(['telur dadar wortel', 'telur dadar'], array_slice($seen, -2));
        Http::assertSentCount(5);
    }
}
