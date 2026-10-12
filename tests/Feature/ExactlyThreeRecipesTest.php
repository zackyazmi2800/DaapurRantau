<?php

namespace Tests\Feature;

use App\Exceptions\RecipeGenerationException;
use App\Services\GeminiRecipeService;
use App\Support\RecipeCacheKey;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\GeminiFixture;
use Tests\TestCase;

class ExactlyThreeRecipesTest extends TestCase
{
    use GeminiFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ai.gemini.api_key' => 'fake-only', 'ai.cache.enabled' => true]);
        Cache::flush();
        Http::preventStrayRequests();
    }

    public function test_partial_and_excess_results_fail_without_history_cache_or_repair_request(): void
    {
        foreach ([0, 1, 2, 4] as $count) {
            $data = $this->recipeData();
            $data['recipes'] = array_slice(array_merge($data['recipes'], $data['recipes']), 0, $count);
            $this->fakeProvider(['*' => Http::response($this->geminiBody($data))]);
            $this->postJson('/rekomendasi', ['ingredients' => ['telur']])->assertStatus(503)
                ->assertJsonPath('message', RecipeGenerationException::INCOMPLETE)->assertJsonMissingPath('recipes');
            Http::assertSentCount(1);
            $this->assertNull(Cache::get(app(RecipeCacheKey::class)->make(['ingredients' => ['telur']])));
        }
        $this->assertDatabaseCount('recipe_searches', 0);
    }

    public function test_duplicate_titles_and_renamed_identical_recipes_are_rejected(): void
    {
        foreach (['title', 'content'] as $duplicate) {
            $data = $this->recipeData();
            if ($duplicate === 'title') {
                $data['recipes'][1]['title'] = ' TELUR   REBUS! ';
            } else {
                $data['recipes'][1] = $data['recipes'][0];
                $data['recipes'][1]['title'] = 'Nama Lain';
                $data['recipes'][1]['ingredients'][0]['quantity'] = '2 butir';
            }
            $this->fakeProvider(['*' => Http::response($this->geminiBody($data))]);
            $this->post('/rekomendasi', ['ingredients' => ['telur']])->assertStatus(503)
                ->assertSee(RecipeGenerationException::INCOMPLETE);
            $this->assertNull(Cache::get(app(RecipeCacheKey::class)->make(['ingredients' => ['telur']])));
            Http::assertSentCount(1);
        }
    }

    public function test_success_has_exactly_three_cards_including_recipes_needing_extra_ingredients(): void
    {
        $this->fakeProvider(['*' => Http::response($this->geminiBody())]);
        $response = $this->post('/rekomendasi', ['ingredients' => ['telur', 'air']])->assertOk()
            ->assertSee('3 ide untuk dapurmu')->assertSee('Butuh 4 bahan tambahan');
        $this->assertCount(3, $response->viewData('snapshot')['recipes']);
        $this->assertSame(3, substr_count($response->getContent(), '<article'));
        $this->postJson('/rekomendasi', ['ingredients' => ['air', 'telur']])->assertOk()->assertJsonCount(3, 'recipes');
        Http::assertSentCount(1);
    }

    public function test_old_version_cache_is_isolated_and_new_partial_cache_is_revalidated(): void
    {
        $input = ['ingredients' => ['telur', 'air']];
        $versions = ['ai.prompt_version' => config('ai.prompt_version'), 'ai.schema_version' => config('ai.schema_version')];
        config(['ai.prompt_version' => '1', 'ai.schema_version' => '2']);
        $oldKey = app(RecipeCacheKey::class)->make($input);
        $partial = $this->recipeData();
        array_pop($partial['recipes']);
        Cache::put($oldKey, $partial, 86400);
        config($versions);
        $key = app(RecipeCacheKey::class)->make($input);
        $this->assertNotSame($oldKey, $key);
        $this->fakeProvider(['*' => Http::response($this->geminiBody())]);
        $this->postJson('/rekomendasi', $input)->assertOk()->assertJsonCount(3, 'recipes');
        $this->assertCount(3, Cache::get($key)['recipes']);
        $this->assertSame($partial, Cache::get($oldKey));
        Cache::put($key, $partial, 86400);
        $this->postJson('/rekomendasi', $input)->assertOk()->assertJsonCount(3, 'recipes');
        $this->postJson('/rekomendasi', ['ingredients' => ['air', 'telur']])->assertOk();
        Http::assertSentCount(2);
    }

    public function test_legacy_partial_snapshot_is_not_displayed_as_success(): void
    {
        $this->fakeProvider(['*' => Http::response($this->geminiBody())]);
        $snapshot = $this->post('/rekomendasi', ['ingredients' => ['telur']])->assertOk()->viewData('snapshot');
        array_pop($snapshot['recipes']);
        Cache::put('recommendation-snapshot:'.$snapshot['id'], $snapshot, 3600);
        $this->get(route('recommendations.show', $snapshot['id']))->assertStatus(410);
        Http::assertSentCount(1);
    }

    public function test_controller_rejects_partial_results_after_matching(): void
    {
        $data = $this->recipeData();
        $data['recipes'][0]['ingredients'][] = ['name' => 'garlic', 'quantity' => '1 siung', 'optional' => true];
        $this->mock(GeminiRecipeService::class, fn ($mock) => $mock->shouldReceive('generateRecipes')->once()->andReturn($data));
        $this->postJson('/rekomendasi', ['ingredients' => ['telur'], 'allergies' => 'bawang putih'])
            ->assertStatus(503)->assertJsonMissingPath('recipes');
        $this->assertDatabaseCount('recipe_searches', 0);
        Http::assertNothingSent();
    }

    public function test_alias_allergen_conflict_never_enters_cache_or_history(): void
    {
        $data = $this->recipeData();
        $data['recipes'][0]['ingredients'][] = ['name' => 'garlic', 'quantity' => '1 siung', 'optional' => true];
        $input = ['ingredients' => ['telur'], 'allergies' => 'bawang putih'];
        $this->fakeProvider(['*' => Http::response($this->geminiBody($data))]);
        $this->postJson('/rekomendasi', $input)->assertStatus(503)->assertJsonMissingPath('recipes');
        $this->assertNull(Cache::get(app(RecipeCacheKey::class)->make($input)));
        $this->assertDatabaseCount('recipe_searches', 0);
    }
}
