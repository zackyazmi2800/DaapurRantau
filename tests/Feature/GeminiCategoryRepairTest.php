<?php

namespace Tests\Feature;

use App\Exceptions\RecipeGenerationException;
use App\Services\GeminiRecipeService;
use App\Services\RecipeMatchingService;
use App\Support\RecipeCacheKey;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Tests\Support\GeminiFixture;
use Tests\TestCase;

class GeminiCategoryRepairTest extends TestCase
{
    use GeminiFixture;

    private array $input = ['ingredients' => ['telur', 'air'], 'allergies' => 'udang'];

    protected function setUp(): void
    {
        parent::setUp();
        config(['ai.gemini.api_key' => 'fake-only', 'ai.cache.enabled' => true]);
        Cache::flush();
        Sleep::fake();
        Http::preventStrayRequests();
    }

    private function invalidCategories(): array
    {
        $data = $this->recipeData();
        $data['recipes'][2]['ingredients'][0]['name'] = 'telur dan garam'; // Invalid combined name, not a category quota.

        return $data;
    }

    public function test_one_targeted_repair_recovers_categories_then_caches_valid_result(): void
    {
        Log::spy();
        $this->fakeProvider(['*' => Http::sequence()->push($this->geminiBody($this->invalidCategories()))->push($this->geminiBody())]);
        $result = app(GeminiRecipeService::class)->generateRecipes($this->input);
        $this->assertSame($this->recipeData(), $result);
        $this->assertSame($result, Cache::get(app(RecipeCacheKey::class)->make($this->input)));
        $this->assertSame($result, app(GeminiRecipeService::class)->generateRecipes($this->input));
        Http::assertSentCount(2);
        Http::assertSent(function ($request) {
            $data = json_decode($request['contents'][0]['parts'][0]['text'], true);
            if (! isset($data['ingredient_feedback'])) {
                return false;
            }
            $this->assertSame(2, $data['ingredient_feedback']['recipe_index']);
            $this->assertSame('combined_ingredient', $data['ingredient_feedback']['kind']);
            $this->assertSame(['udang'], $data['allergies']);
            $this->assertCount(1, $request['contents']);
            $this->assertArrayNotHasKey('recipes', $data);
            $this->assertSame(3000, $request['generationConfig']['maxOutputTokens']);

            return true;
        });
        Log::shouldHaveReceived('warning')->with('gemini.ingredient_validation', ['kind' => 'combined_ingredient', 'recipe_index' => 2])->once();
    }

    public function test_combined_ingredient_has_separate_safe_diagnosis_and_can_be_repaired(): void
    {
        Log::spy();
        $bad = $this->recipeData();
        $bad['recipes'][0]['ingredients'][1]['name'] = 'air dan garam';
        $this->fakeProvider(['*' => Http::sequence()->push($this->geminiBody($bad))->push($this->geminiBody())]);
        $this->assertSame($this->recipeData(), app(GeminiRecipeService::class)->generateRecipes($this->input));
        Log::shouldHaveReceived('warning')->with('gemini.ingredient_validation', ['kind' => 'combined_ingredient', 'recipe_index' => 0])->once();
        Http::assertSentCount(2);
    }

    public function test_repair_failure_or_quota_never_causes_a_third_request_or_cache_write(): void
    {
        foreach (['category', 'quota', 'server', 'allergen', 'truncated'] as $failure) {
            Cache::flush();
            $unsafe = $this->recipeData();
            $unsafe['recipes'][0]['ingredients'][] = ['name' => 'udang', 'quantity' => '1', 'optional' => false];
            $second = match ($failure) {
                'category' => $this->geminiBody($this->invalidCategories()),
                'allergen' => $this->geminiBody($unsafe),
                'truncated' => $this->geminiBody(finish: 'MAX_TOKENS'),
                default => [],
            };
            $status = match ($failure) {
                'quota' => 429, 'server' => 503, default => 200
            };
            $this->fakeProvider(['*' => Http::sequence()->push($this->geminiBody($this->invalidCategories()))->push($second, $status)]);
            try {
                app(GeminiRecipeService::class)->generateRecipes($this->input);
                $this->fail('Invalid repair accepted.');
            } catch (RecipeGenerationException $exception) {
                $this->assertSame(match ($failure) {
                    'category' => 'invalid_ingredient_names', 'quota' => 'provider_quota', 'server' => 'provider_unavailable',
                    'allergen' => 'allergen_conflict', 'truncated' => 'incomplete_or_blocked',
                }, $exception->reason);
            }
            Http::assertSentCount(2);
            $this->assertNull(Cache::get(app(RecipeCacheKey::class)->make($this->input)));
        }
    }

    public function test_transport_retry_and_category_repair_share_two_call_budget(): void
    {
        $this->fakeProvider(['*' => Http::sequence()->push([], 503)->push($this->geminiBody($this->invalidCategories()))]);
        try {
            app(GeminiRecipeService::class)->generateRecipes($this->input);
            $this->fail('Invalid categories accepted.');
        } catch (RecipeGenerationException $exception) {
            $this->assertSame('invalid_ingredient_names', $exception->reason);
        }
        Http::assertSentCount(2);
    }

    public function test_controller_displays_only_repaired_three_categories_and_records_one_search(): void
    {
        $this->fakeProvider(['*' => Http::sequence()->push($this->geminiBody($this->invalidCategories()))->push($this->geminiBody())]);
        $response = $this->post('/rekomendasi', $this->input)->assertOk()
            ->assertSeeInOrder(['Paling Cocok', 'Cukup Cocok', 'Alternatif Menu']);
        $this->assertSame([0, 1, 4], array_column(array_column($response->viewData('snapshot')['recipes'], 'match'), 'missing_ingredients_count'));
        $this->assertDatabaseCount('recipe_searches', 1);
        Http::assertSentCount(2);
    }

    public function test_zero_first_repair_improves_result_and_cache_hit_does_not_retry(): void
    {
        $one = $this->recipeData();
        $one['recipes'][0]['ingredients'][] = ['name' => 'garam', 'quantity' => '1 gram', 'optional' => false];
        $this->fakeProvider(['*' => Http::sequence()->push($this->geminiBody($one))->push($this->geminiBody())]);
        $service = app(GeminiRecipeService::class);
        $result = $service->generateRecipes($this->input);
        $ranked = app(RecipeMatchingService::class)->rank($result['recipes'], $this->input);
        $this->assertSame(0, $ranked[0]['match']['missing_ingredients_count']);
        $this->assertSame($result, $service->generateRecipes($this->input));
        Http::assertSentCount(2);
    }

    public function test_optional_optimization_failure_retains_valid_original_without_third_call(): void
    {
        $data = $this->recipeData();
        $data['recipes'][0]['ingredients'][] = ['name' => 'garam', 'quantity' => '1 gram', 'optional' => false];
        $this->fakeProvider(['*' => Http::sequence()->push($this->geminiBody($data))->push([], 429)]);
        $result = app(GeminiRecipeService::class)->generateRecipes($this->input);
        $ranked = app(RecipeMatchingService::class)->rank($result['recipes'], $this->input);
        $this->assertSame(1, $ranked[0]['match']['missing_ingredients_count']);
        Http::assertSentCount(2);
    }

    public function test_more_than_two_missing_never_receives_best_badge_or_cache(): void
    {
        $data = $this->recipeData();
        foreach ($data['recipes'] as &$recipe) {
            foreach (['bawang merah', 'bawang putih', 'cabai'] as $name) {
                $recipe['ingredients'][] = ['name' => $name, 'quantity' => '1 buah', 'optional' => false];
            }
        }
        unset($recipe);
        $this->fakeProvider(['*' => Http::sequence()->push($this->geminiBody($data))->push($this->geminiBody($data))]);
        try {
            app(GeminiRecipeService::class)->generateRecipes($this->input);
            $this->fail('Invalid best category accepted.');
        } catch (RecipeGenerationException $exception) {
            $this->assertSame('no_best_match', $exception->reason);
        }
        Http::assertSentCount(2);
        $this->assertNull(Cache::get(app(RecipeCacheKey::class)->make($this->input)));
    }

    public function test_ranking_ignores_provider_order_and_optional_missing_items(): void
    {
        $recipes = $this->recipeData()['recipes'];
        $recipes[0]['ingredients'][] = ['name' => 'lada', 'quantity' => 'sejumput', 'optional' => true];
        $ranked = app(RecipeMatchingService::class)->rank(array_reverse($recipes), $this->input);
        $this->assertSame('Telur Rebus', $ranked[0]['title']);
        $this->assertSame(0, $ranked[0]['match']['missing_ingredients_count']);
        $this->assertSame('Paling Cocok', $ranked[0]['match']['category']);
    }

    public function test_two_missing_is_allowed_and_ties_favor_more_owned_required_ingredients(): void
    {
        $recipes = $this->recipeData()['recipes'];
        $recipes[0]['ingredients'][] = ['name' => 'minyak', 'quantity' => '1 sendok', 'optional' => false];
        $recipes[0]['ingredients'][] = ['name' => 'garam', 'quantity' => '1 gram', 'optional' => false];
        $recipes[1]['ingredients'][] = ['name' => 'minyak', 'quantity' => '1 sendok', 'optional' => false];
        $recipes[1]['ingredients'][] = ['name' => 'nasi', 'quantity' => '1 piring', 'optional' => false];
        $ranked = app(RecipeMatchingService::class)->rank($recipes, ['ingredients' => ['telur', 'air', 'nasi']]);
        $this->assertSame('Telur Kukus', $ranked[0]['title']);
        $this->assertSame(2, $ranked[0]['match']['missing_ingredients_count']);
    }

    public function test_invalid_step_count_is_repaired_once_with_safe_field_feedback(): void
    {
        $bad = $this->recipeData();
        array_pop($bad['recipes'][0]['steps']);
        $this->fakeProvider(['*' => Http::sequence()->push($this->geminiBody($bad))->push($this->geminiBody())]);
        $result = app(GeminiRecipeService::class)->generateRecipes($this->input);
        $this->assertSame($this->recipeData(), $result);
        Http::assertSent(fn ($request) => (json_decode($request['contents'][0]['parts'][0]['text'], true)['structure_feedback'] ?? null) === [
            'field' => 'response.recipes.0.steps', 'count' => 4, 'minimum' => 5, 'maximum' => 10,
        ]);
        Http::assertSentCount(2);
        $this->assertSame($result, Cache::get(app(RecipeCacheKey::class)->make($this->input)));
    }

    public function test_invalid_step_count_after_transport_retry_never_triggers_third_request(): void
    {
        $bad = $this->recipeData();
        array_pop($bad['recipes'][0]['steps']);
        $this->fakeProvider(['*' => Http::sequence()->push([], 503)->push($this->geminiBody($bad))]);
        try {
            app(GeminiRecipeService::class)->generateRecipes($this->input);
            $this->fail('Invalid structure accepted.');
        } catch (RecipeGenerationException $exception) {
            $this->assertSame('invalid_item_count', $exception->reason);
        }
        Http::assertSentCount(2);
        $this->assertNull(Cache::get(app(RecipeCacheKey::class)->make($this->input)));
    }
}
