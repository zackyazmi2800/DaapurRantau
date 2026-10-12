<?php

namespace Tests\Feature;

use App\Models\SavedRecipe;
use App\Services\SavedRecipeService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\GeminiFixture;
use Tests\Support\UnsplashFixture;
use Tests\TestCase;

class RecipeImagePagesTest extends TestCase
{
    use GeminiFixture, UnsplashFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ai.gemini.api_key' => 'gemini-test-secret', 'services.unsplash.access_key' => 'unsplash-test-secret']);
        Cache::flush();
        Http::preventStrayRequests();
    }

    public function test_three_distinct_images_reach_cards_details_and_favorites_with_correct_urls(): void
    {
        $data = $this->recipeData();
        $titles = ['Sup Segar Jagung Wortel Tahu', 'Sayur Bening Bayam Jagung', 'Tumis Tahu Wortel Pedas'];
        foreach ($titles as $i => $title) {
            $data['recipes'][$i]['title'] = $title;
        }
        $this->fakeProvider([
            'generativelanguage.googleapis.com/*' => Http::response($this->geminiBody($data)),
            'api.unsplash.com/*' => function ($request) {
                $id = str_starts_with($request['query'], 'sup') ? 'soup' : (str_starts_with($request['query'], 'sayur') ? 'clear' : 'stir');
                $photo = $this->photo(match ($id) {
                    'soup' => 'Bowl of soup with tofu', 'clear' => 'Clear vegetable soup with spinach', default => 'Stir fry with tofu and carrot',
                }, $id);
                $photo['urls']['small'] = 'https://images.unsplash.com/'.$id.'?w=400';
                $photo['urls']['regular'] = 'https://images.unsplash.com/'.$id.'?w=1080';

                return Http::response(['results' => [$photo]]);
            },
        ]);
        $response = $this->post('/rekomendasi', ['ingredients' => ['telur', 'air']])->assertOk();
        $snapshot = $response->viewData('snapshot');
        $this->assertSame(['soup', 'clear', 'stir'], array_column(array_column($snapshot['recipes'], 'image'), 'unsplash_photo_id'));
        foreach ($snapshot['recipes'] as $recipe) {
            $response->assertSee('src="'.$recipe['image']['image_small_url'].'"', false);
            $this->get(route('recommendations.detail', [$snapshot['id'], $recipe['id']]))->assertOk()
                ->assertSee('src="'.$recipe['image']['image_url'].'"', false);
            $this->post(route('favorites.store', [$snapshot['id'], $recipe['id']]))->assertRedirect();
        }
        foreach (SavedRecipe::all() as $saved) {
            $this->get(route('favorites.show', $saved->id))->assertOk()->assertSee('src="'.$saved->recipe_data['image']['image_url'].'"', false);
        }
        $this->post('/rekomendasi', ['ingredients' => ['air', 'telur']])->assertOk();
        Http::assertSentCount(4);
    }

    public function test_images_and_attribution_persist_through_details_favorites_and_history_without_new_requests(): void
    {
        $this->fakeProvider([
            'generativelanguage.googleapis.com/*' => Http::response($this->geminiBody()),
            'api.unsplash.com/*' => Http::response(['results' => [$this->photo('A boiled egg')]]),
        ]);
        $response = $this->post('/rekomendasi', ['ingredients' => ['telur', 'air']])->assertOk()
            ->assertSee('Fotografer Uji')->assertSee('Unsplash')->assertSee('utm_source=dapur_rantau')
            ->assertSee('images.unsplash.com/photo-test', false)->assertSee('data-recipe-image', false)
            ->assertDontSee('unsplash-test-secret')->assertDontSee('gemini-test-secret');
        $snapshot = $response->viewData('snapshot');
        $this->assertCount(3, $snapshot['recipes']);
        $this->assertSame(['Paling Cocok', 'Cukup Cocok', 'Alternatif Menu'], array_column(array_column($snapshot['recipes'], 'match'), 'category'));
        $this->assertSame([0, 1, 4], array_column(array_column($snapshot['recipes'], 'match'), 'missing_ingredients_count'));
        Http::assertSentCount(8); // Gemini + boiled egg success + two steamed egg levels + four omelette levels.
        $recipe = $snapshot['recipes'][0];
        $fingerprint = app(SavedRecipeService::class)->fingerprint($recipe);
        $withoutImage = $recipe;
        unset($withoutImage['image']);
        $this->assertSame($fingerprint, app(SavedRecipeService::class)->fingerprint($withoutImage));
        $unexpected = 0;
        $this->fakeProvider(function () use (&$unexpected) {
            $unexpected++;

            return Http::response([], 500);
        });
        $this->get(route('recommendations.detail', [$snapshot['id'], $recipe['id']]))->assertOk()
            ->assertSee('images.unsplash.com/photo-test', false)->assertSee('Fotografer Uji');
        $this->post(route('favorites.store', [$snapshot['id'], $recipe['id']]), ['image' => ['image_url' => 'https://evil.test']])->assertRedirect();
        $saved = SavedRecipe::firstOrFail();
        $this->assertSame($recipe['image'], $saved->recipe_data['image']);
        $this->get('/favorit')->assertOk()->assertSee('images.unsplash.com/photo-test', false)->assertSee('Fotografer Uji');
        $this->get(route('favorites.show', $saved->id))->assertOk()->assertSee('images.unsplash.com/photo-test', false)->assertSee('Unsplash');
        $this->get('/riwayat')->assertOk();
        $this->post('/rekomendasi', ['ingredients' => ['air', 'telur']])->assertOk();
        Http::assertNothingSent();
        $this->assertSame(0, $unexpected);
        $this->assertDatabaseCount('saved_recipes', 1);
    }

    public function test_unsplash_failure_does_not_change_three_gemini_recipes_or_categories(): void
    {
        $this->fakeProvider([
            'generativelanguage.googleapis.com/*' => Http::response($this->geminiBody()),
            'api.unsplash.com/*' => Http::response([], 500),
        ]);
        $response = $this->post('/rekomendasi', ['ingredients' => ['telur', 'air']])->assertOk()
            ->assertSee('recipe-placeholder.svg', false)->assertSeeInOrder(['Paling Cocok', 'Cukup Cocok', 'Alternatif Menu']);
        $this->assertCount(3, $response->viewData('snapshot')['recipes']);
        Http::assertSentCount(4); // One Gemini and three independent pooled searches.
    }

    public function test_old_favorite_without_image_remains_readable_with_placeholder(): void
    {
        $this->fakeProvider([
            'generativelanguage.googleapis.com/*' => Http::response($this->geminiBody()),
            'api.unsplash.com/*' => Http::response(['results' => []]),
        ]);
        $snapshot = $this->post('/rekomendasi', ['ingredients' => ['telur', 'air']])->assertOk()->viewData('snapshot');
        $this->post(route('favorites.store', [$snapshot['id'], $snapshot['recipes'][0]['id']]))->assertRedirect();
        $saved = SavedRecipe::firstOrFail();
        $data = $saved->recipe_data;
        unset($data['image']);
        $saved->update(['recipe_data' => $data]);
        $this->fakeProvider([]);
        $this->get('/favorit')->assertOk()->assertSee('recipe-placeholder.svg', false);
        $this->get(route('favorites.show', $saved->id))->assertOk()->assertSee('recipe-placeholder.svg', false);
        Http::assertNothingSent();
        $this->assertDatabaseCount('saved_recipes', 1);
    }
}
