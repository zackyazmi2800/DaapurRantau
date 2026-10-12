<?php

namespace Tests\Feature;

use App\Exceptions\RecipeGenerationException;
use App\Services\GeminiRecipeService;
use App\Services\RecipeMatchingService;
use App\Support\RecipeCacheKey;
use App\Support\RecipeMode;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\GeminiFixture;
use Tests\TestCase;

class RecipeCategoriesTest extends TestCase
{
    use GeminiFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ai.gemini.api_key' => 'fake-only', 'ai.cache.enabled' => true]);
        Cache::flush();
        Http::preventStrayRequests();
    }

    public function test_shuffled_recipes_have_correct_badges_and_identical_card_detail_counts_without_extra_http(): void
    {
        $data = $this->recipeData();
        $data['recipes'] = array_reverse($data['recipes']);
        $this->fakeProvider(['*' => Http::response($this->geminiBody($data))]);
        $response = $this->post('/rekomendasi', ['ingredients' => ['telur', 'air']])->assertOk()
            ->assertSeeInOrder(['Paling Cocok', 'Cukup Cocok', 'Alternatif Menu'])
            ->assertSee('Semua bahan tersedia')->assertSee('Butuh 1 bahan tambahan')->assertSee('Butuh 4 bahan tambahan');
        $snapshot = $response->viewData('snapshot');
        $this->assertSame([0, 1, 4], array_column(array_column($snapshot['recipes'], 'match'), 'missing_ingredients_count'));
        foreach ($snapshot['recipes'] as $recipe) {
            $count = $recipe['match']['missing_ingredients_count'];
            $detail = $this->get(route('recommendations.detail', [$snapshot['id'], $recipe['id']]))->assertOk()
                ->assertSee($recipe['match']['category'])->assertSee($count ? "Kamu masih membutuhkan $count bahan tambahan." : 'Semua bahan sudah tersedia. Yuk, mulai masak!')
                ->assertSee('Bahan tersedia')->assertSee('Bahan tambahan')->assertSee('Bahan opsional');
            $this->assertSame($recipe['match'], $detail->viewData('recipe')['match']);
        }
        $this->postJson('/rekomendasi', ['ingredients' => ['air', 'telur']])->assertOk()->assertJsonCount(3, 'recipes');
        Http::assertSentCount(1);
    }

    public function test_no_suitable_candidate_is_rejected_after_bounded_repair(): void
    {
        $data = $this->recipeData();
        $data['recipes'][0]['ingredients'][] = ['name' => 'garam', 'quantity' => 'sejumput', 'optional' => false];
        $data['recipes'][1]['ingredients'][] = ['name' => 'minyak', 'quantity' => '1 sendok teh', 'optional' => false];
        $data['recipes'][1]['ingredients'][] = ['name' => 'wortel', 'quantity' => '1 buah', 'optional' => false];
        $data['recipes'] = array_reverse($data['recipes']);
        $this->fakeProvider(['*' => Http::response($this->geminiBody($data))]);
        $input = ['ingredients' => ['telur', 'nasi']];
        $this->post('/rekomendasi', $input)->assertStatus(503);
        $this->assertNull(Cache::get(app(RecipeCacheKey::class)->make($input)));
        $this->assertDatabaseCount('recipe_searches', 0);
        Http::assertSentCount(2);
    }

    public function test_all_zero_candidates_use_alternative_fallback_after_repair(): void
    {
        $this->fakeProvider(['*' => Http::response($this->geminiBody())]);
        $input = ['ingredients' => ['telur', 'air', 'garam', 'minyak', 'wortel', 'daun bawang']];
        $response = $this->post('/rekomendasi', $input)->assertOk();
        $this->assertSame([0, 0, 0], array_column(array_column($response->viewData('snapshot')['recipes'], 'match'), 'missing_ingredients_count'));
        $this->assertNotNull(Cache::get(app(RecipeCacheKey::class)->make($input)));
        Http::assertSentCount(2);
    }

    public function test_combined_ingredients_are_not_cached_or_recorded_after_one_repair(): void
    {
        $data = $this->recipeData();
        $data['recipes'][2]['ingredients'] = [['name' => 'telur dan garam', 'quantity' => '1 butir', 'optional' => false]];
        $this->fakeProvider(['*' => Http::response($this->geminiBody($data))]);
        $input = ['ingredients' => ['telur', 'air']];
        $this->post('/rekomendasi', $input)->assertStatus(503)->assertSee(RecipeGenerationException::CATEGORIES)
            ->assertDontSee('Telur Rebus');
        Http::assertSentCount(2);
        $this->assertNull(Cache::get(app(RecipeCacheKey::class)->make($input)));
        $this->assertDatabaseCount('recipe_searches', 0);
    }

    public function test_old_grouping_cache_is_isolated_and_invalid_current_entry_is_evicted(): void
    {
        $input = ['ingredients' => ['telur', 'air']];
        $data = $this->recipeData();
        $data['recipes'][2]['ingredients'] = [['name' => 'telur dan garam', 'quantity' => '1', 'optional' => false]];
        $version = config('ai.prompt_version');
        config(['ai.prompt_version' => '2']);
        $old = app(RecipeCacheKey::class)->make($input);
        Cache::put($old, $data, 86400);
        config(['ai.prompt_version' => $version]);
        $key = app(RecipeCacheKey::class)->make($input);
        $this->assertNotSame($old, $key);
        Cache::put($key, $data, 86400);
        $this->fakeProvider(['*' => Http::response($this->geminiBody())]);
        $this->postJson('/rekomendasi', $input)->assertOk();
        $this->assertSame($this->recipeData(), Cache::get($key));
        $this->assertSame($data, Cache::get($old));
        $this->postJson('/rekomendasi', $input)->assertOk();
        Http::assertSentCount(1);
    }

    public function test_legacy_snapshot_without_category_version_cannot_show_misleading_badges(): void
    {
        $this->fakeProvider(['*' => Http::response($this->geminiBody())]);
        $snapshot = $this->post('/rekomendasi', ['ingredients' => ['telur', 'air']])->assertOk()->viewData('snapshot');
        unset($snapshot['matching_version']);
        Cache::put('recommendation-snapshot:'.$snapshot['id'], $snapshot, 3600);
        $this->get(route('recommendations.show', $snapshot['id']))->assertStatus(410);
        Http::assertSentCount(1);
    }

    public function test_suitable_range_and_alternative_fallback_counts_are_validated(): void
    {
        $matching = app(RecipeMatchingService::class);
        foreach ([0, 1, 2, 3] as $middle) {
            foreach ([4, 5, 6] as $last) {
                $recipes = $this->recipeData()['recipes'];
                $recipes[1]['ingredients'] = array_slice($recipes[1]['ingredients'], 0, 2);
                foreach (array_slice(['garam', 'minyak', 'wortel'], 0, $middle) as $name) {
                    $recipes[1]['ingredients'][] = ['name' => $name, 'quantity' => '1', 'optional' => false];
                }
                foreach (array_slice(['bawang merah', 'cabai'], 0, $last - 4) as $name) {
                    $recipes[2]['ingredients'][] = ['name' => $name, 'quantity' => '1', 'optional' => false];
                }
                $ranked = $matching->rank([$recipes[2], $recipes[0], $recipes[1]], ['ingredients' => ['telur', 'air']]);
                $this->assertSame([0, $middle, $last], array_column(array_column($ranked, 'match'), 'missing_ingredients_count'));
                $this->assertSame('Telur Rebus', $ranked[0]['title']);
            }
        }
    }

    public function test_missing_category_is_repaired_once_then_cached(): void
    {
        $bad = $this->recipeData();
        array_pop($bad['recipes'][2]['ingredients']); // Only 3 missing; no alternative.
        $this->fakeProvider(['*' => Http::sequence()->push($this->geminiBody($bad))->push($this->geminiBody())]);
        $input = ['ingredients' => ['telur', 'air']];
        $response = $this->post('/rekomendasi', $input)->assertOk();
        $this->assertSame([0, 1, 4], array_column(array_column($response->viewData('snapshot')['recipes'], 'match'), 'missing_ingredients_count'));
        Http::assertSent(fn ($request) => str_contains($request['systemInstruction']['parts'][0]['text'], 'Perbaikan Alternatif Menu satu kali'));
        $this->postJson('/rekomendasi', ['ingredients' => ['air', 'telur']])->assertOk();
        Http::assertSentCount(2);
    }

    public function test_normal_priority_version_leaves_strict_cache_unchanged(): void
    {
        $keys = app(RecipeCacheKey::class);
        $input = ['ingredients' => ['telur', 'air']];
        $strict = $input + ['mode' => RecipeMode::STRICT];
        $normalKey = $keys->make($input);
        $strictKey = $keys->make($strict);
        config(['ai.normal_recommendation_version' => 'zero-first-1']);
        $this->assertNotSame($normalKey, $keys->make($input));
        $this->assertSame($strictKey, $keys->make($strict));
    }

    public function test_alternative_fallback_zero_through_three_is_cached_only_after_bounded_attempt(): void
    {
        foreach ([0, 1, 2, 3] as $missing) {
            Cache::flush();
            $data = $this->recipeData();
            foreach (['minyak', 'wortel'] as $name) {
                $data['recipes'][1]['ingredients'][] = ['name' => $name, 'quantity' => '1', 'optional' => false];
            }
            $data['recipes'][2]['ingredients'] = [['name' => 'telur', 'quantity' => '1', 'optional' => false]];
            foreach (array_slice(['cabai', 'bawang merah', 'tomat'], 0, $missing) as $name) {
                $data['recipes'][2]['ingredients'][] = ['name' => $name, 'quantity' => '1', 'optional' => false];
            }
            $this->fakeProvider(['*' => Http::sequence()->push($this->geminiBody($data))->push([], 429)]);
            $input = ['ingredients' => ['telur', 'air']];
            $service = app(GeminiRecipeService::class);
            $result = $service->generateRecipes($input);
            $ranked = app(RecipeMatchingService::class)->rank($result['recipes'], $input);
            $this->assertSame([0, 3, $missing], array_column(array_column($ranked, 'match'), 'missing_ingredients_count'));
            $this->assertCount(3, array_unique(array_column($ranked, 'title')));
            $this->assertSame($result, $service->generateRecipes($input));
            Http::assertSentCount(2);
        }
    }

    public function test_existing_primary_alternative_is_retained_when_zero_repair_returns_fallback(): void
    {
        $first = $this->recipeData();
        $first['recipes'][0]['ingredients'][] = ['name' => 'garam', 'quantity' => '1', 'optional' => false];
        $second = $this->recipeData();
        array_pop($second['recipes'][2]['ingredients']);
        $this->fakeProvider(['*' => Http::sequence()->push($this->geminiBody($first))->push($this->geminiBody($second))]);
        $input = ['ingredients' => ['telur', 'air']];
        $result = app(GeminiRecipeService::class)->generateRecipes($input);
        $ranked = app(RecipeMatchingService::class)->rank($result['recipes'], $input);
        $this->assertSame(4, $ranked[2]['match']['missing_ingredients_count']);
        $this->assertCount(3, array_unique(array_column($ranked, 'title')));
        Http::assertSentCount(2);
    }
}
