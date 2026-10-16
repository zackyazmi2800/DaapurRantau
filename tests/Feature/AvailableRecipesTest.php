<?php

namespace Tests\Feature;

use App\Models\RecipeSearch;
use App\Models\SavedRecipe;
use App\Services\GeminiRecipeService;
use App\Support\RecipeCacheKey;
use App\Support\RecipeMode;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\GeminiFixture;
use Tests\TestCase;

class AvailableRecipesTest extends TestCase
{
    use GeminiFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ai.gemini.api_key' => 'fake-only', 'ai.cache.enabled' => true]);
        Cache::flush();
        Http::preventStrayRequests();
    }

    private function normal(): array
    {
        $this->fakeProvider(['*' => Http::response($this->geminiBody())]);

        return $this->post('/rekomendasi', ['ingredients' => ['telur', 'air'], 'allergies' => 'udang'])->assertOk()->viewData('snapshot');
    }

    public function test_checklist_starts_from_user_stock_and_updates_without_ai_across_detail_pages(): void
    {
        $snapshot = $this->normal();
        $this->fakeProvider([]);
        $url = route('recommendations.detail', [$snapshot['id'], $snapshot['recipes'][1]['id']]);
        $detail = $this->get($url)->assertOk()->assertSee('Bahan wajib')->assertSee('Bahan opsional')->assertSee('Kamu masih membutuhkan 1 bahan tambahan.');
        $state = $detail->viewData('checklist');
        $this->assertTrue($state['items'][hash('sha256', 'telur')]['available']);
        $this->assertFalse($state['items'][hash('sha256', 'garam')]['available']);
        $dom = new \DOMDocument;
        @$dom->loadHTML($detail->getContent());
        $xpath = new \DOMXPath($dom);
        $this->assertSame(2, $xpath->query('//input[@data-stock-item and @checked]')->length);
        $confirmed = [hash('sha256', 'telur'), hash('sha256', 'garam')];
        $this->patchJson(route('recommendations.checklist', $snapshot['id']), compact('confirmed'))->assertOk();
        $updated = $this->get($url)->assertOk()->viewData('checklist');
        $this->assertSame(['telur', 'garam'], $updated['ingredients']);
        $this->assertSame('air', $updated['match']['missing'][0]['name']);
        $other = $this->get(route('recommendations.detail', [$snapshot['id'], $snapshot['recipes'][0]['id']]))->assertOk()->viewData('checklist');
        $this->assertContains(hash('sha256', 'garam'), $other['other']);
        Http::assertNothingSent();
    }

    public function test_alternative_sends_only_confirmed_stock_and_preserves_options_allergies_and_history(): void
    {
        $snapshot = $this->normal();
        $data = $this->recipeData();
        $this->fakeProvider(['*' => Http::response($this->geminiBody($data))]);
        $response = $this->post(route('recommendations.alternatives', $snapshot['id']), ['confirmed' => [hash('sha256', 'telur'), hash('sha256', 'air')]])->assertOk()
            ->assertSee('Masak dari Bahan yang Kamu Punya')->assertSee('Tanpa Bahan Tambahan')->assertDontSee('Cukup Cocok');
        $strict = $response->viewData('snapshot');
        $this->assertCount(1, $strict['recipes']);
        $this->assertSame('Telur Rebus', $strict['recipes'][0]['title']);
        Http::assertSent(function ($request) {
            $input = json_decode($request['contents'][0]['parts'][0]['text'], true);

            return $input['ingredients'] === ['air', 'telur'] && $input['allergies'] === ['udang']
                && $input['mode'] === RecipeMode::STRICT
                && $request['generationConfig']['responseJsonSchema']['properties']['recipes']['minItems'] === 0
                && str_contains($request['systemInstruction']['parts'][0]['text'], '0–3');
        });
        $this->assertSame(RecipeMode::STRICT, RecipeSearch::latest('id')->first()->preferences['mode']);
        $this->fakeProvider([]);
        $recipe = $strict['recipes'][0];
        $this->get(route('recommendations.detail', [$strict['id'], $recipe['id']]))->assertOk();
        $this->post(route('favorites.store', [$strict['id'], $recipe['id']]))->assertRedirect();
        $saved = SavedRecipe::firstOrFail();
        $this->get(route('favorites.show', $saved->id))->assertOk()->assertSee('Telur Rebus');
        $this->get(route('recommendations.show', $strict['id']))->assertOk();
        $this->get('/riwayat')->assertOk()->assertSee('Tanpa Bahan Tambahan');
        Http::assertNothingSent();
    }

    public function test_unchecked_original_and_unconfirmed_extras_never_reach_gemini(): void
    {
        $snapshot = $this->normal();
        $this->fakeProvider(['*' => Http::response($this->geminiBody(['message' => 'Tidak ada menu.', 'recipes' => []]))]);
        $this->post(route('recommendations.alternatives', $snapshot['id']), ['confirmed' => [hash('sha256', 'garam')]])->assertOk()->assertSee('Belum ada masakan yang bisa dibuat hanya dari bahan ini.');
        Http::assertSent(fn ($request) => json_decode($request['contents'][0]['parts'][0]['text'], true)['ingredients'] === ['garam']);
        Http::assertSentCount(1);
    }

    public function test_strict_accepts_zero_one_two_or_three_and_cache_hit_is_separate_from_normal(): void
    {
        foreach ([0, 1, 2, 3] as $count) {
            Cache::flush();
            $data = $this->recipeData();
            $data['recipes'] = array_slice($data['recipes'], 0, $count);
            $this->fakeProvider(['*' => Http::response($this->geminiBody($data))]);
            $input = ['ingredients' => ['telur', 'air', 'garam', 'minyak', 'wortel', 'daun bawang'], 'mode' => RecipeMode::STRICT];
            $service = app(GeminiRecipeService::class);
            $result = $service->generateRecipes($input);
            $this->assertCount($count, $result['recipes']);
            $this->assertSame($result, $service->generateRecipes($input));
            $keys = app(RecipeCacheKey::class);
            $this->assertNotSame($keys->make($input), $keys->make(array_replace($input, ['mode' => RecipeMode::NORMAL])));
            Http::assertSentCount(1);
        }
    }

    public function test_strict_result_pages_and_snapshots_support_every_allowed_recipe_count(): void
    {
        foreach ([0, 1, 2, 3] as $count) {
            Cache::flush();
            $source = $this->normal();
            $data = $this->recipeData();
            $data['recipes'] = array_slice($data['recipes'], 0, $count);
            $this->fakeProvider(['*' => Http::response($this->geminiBody($data))]);
            $response = $this->post(route('recommendations.alternatives', $source['id']), ['confirmed' => array_map(fn ($name) => hash('sha256', $name), ['telur', 'air', 'garam', 'minyak', 'wortel', 'daun bawang'])])->assertOk();
            $snapshot = $response->viewData('snapshot');
            $this->assertCount($count, $snapshot['recipes']);
            $response->assertDontSee('Paling Cocok')->assertDontSee('Cukup Cocok')->assertDontSee('Alternatif Menu');
            $this->get(route('recommendations.show', $snapshot['id']))->assertOk();
            $this->assertSame($count, RecipeSearch::latest('id')->first()->recipe_count);
            Http::assertSentCount(1);
        }
    }

    public function test_optional_checkboxes_are_separate_and_initially_follow_real_stock(): void
    {
        $data = $this->recipeData();
        $data['recipes'][0]['ingredients'][] = ['name' => 'merica', 'quantity' => 'sejumput', 'optional' => true];
        $this->fakeProvider(['*' => Http::response($this->geminiBody($data))]);
        $snapshot = $this->post('/rekomendasi', ['ingredients' => ['telur', 'air']])->assertOk()->viewData('snapshot');
        $response = $this->get(route('recommendations.detail', [$snapshot['id'], $snapshot['recipes'][0]['id']]))->assertOk();
        $dom = new \DOMDocument;
        @$dom->loadHTML($response->getContent());
        $xpath = new \DOMXPath($dom);
        $this->assertSame(1, $xpath->query('//input[@data-optional="true" and not(@checked)]')->length);
        $this->assertSame(0, $response->viewData('checklist')['match']['missing_ingredients_count']);
        Http::assertSentCount(1);
    }

    public function test_tampered_strict_cache_is_filtered_again_before_use(): void
    {
        $input = ['ingredients' => ['telur', 'air'], 'mode' => RecipeMode::STRICT];
        $key = app(RecipeCacheKey::class)->make($input);
        Cache::put($key, $this->recipeData(), 100);
        $this->fakeProvider([]);
        $result = app(GeminiRecipeService::class)->generateRecipes($input);
        $this->assertCount(1, $result['recipes']);
        $this->assertSame($result, Cache::get($key));
        Http::assertNothingSent();
    }

    public function test_strict_edit_and_history_return_to_normal_without_losing_snapshots(): void
    {
        $normal = $this->normal();
        $strict = $this->post(route('recommendations.alternatives', $normal['id']), [
            'confirmed' => [hash('sha256', 'telur'), hash('sha256', 'air')],
        ])->assertOk()->viewData('snapshot');
        $this->assertCount(1, $strict['recipes']);
        $history = RecipeSearch::latest('id')->first();
        $this->fakeProvider([]);
        foreach ([route('recommendations.edit', $strict['id']), route('history.repeat', $history->id)] as $url) {
            $this->get($url)->assertRedirect()->assertSessionHas('_old_input.mode', RecipeMode::NORMAL);
            $this->get('/')->assertOk()->assertDontSee('name="mode"', false)->assertSee('data-ingredient-limit="15"', false);
        }
        // A stale/tampered browser still cannot select strict through Cari Ide.
        $result = $this->post('/rekomendasi', ['ingredients' => ['telur', 'air'], 'allergies' => 'udang', 'mode' => RecipeMode::STRICT])->assertOk()->viewData('snapshot');
        $this->assertSame(RecipeMode::NORMAL, $result['input']['mode']);
        $this->assertCount(3, $result['recipes']);
        $this->assertCount(3, array_unique(array_column($result['recipes'], 'title')));
        foreach ([$normal, $strict] as $snapshot) {
            $this->get(route('recommendations.detail', [$snapshot['id'], $snapshot['recipes'][0]['id']]))->assertOk();
        }
        Http::assertNothingSent();
    }

    public function test_cari_ide_ignores_invalid_mode_and_enforces_normal_limit(): void
    {
        $this->normal();
        $this->fakeProvider([]);
        $response = $this->post('/rekomendasi', ['ingredients' => ['telur', 'air'], 'allergies' => 'udang', 'mode' => ['invalid']])->assertOk();
        $this->assertSame(RecipeMode::NORMAL, $response->viewData('snapshot')['input']['mode']);
        $this->postJson('/rekomendasi', ['ingredients' => array_map(fn ($i) => 'bahan '.$i, range(1, 16)), 'mode' => RecipeMode::STRICT])
            ->assertUnprocessable()->assertJsonValidationErrors('ingredients');
        Http::assertNothingSent();
    }

    public function test_strict_filters_missing_ingredients_and_does_not_cache_rejected_recipes(): void
    {
        $this->fakeProvider(['*' => Http::response($this->geminiBody())]);
        $input = ['ingredients' => ['telur', 'air'], 'mode' => RecipeMode::STRICT];
        $result = app(GeminiRecipeService::class)->generateRecipes($input);
        $this->assertCount(1, $result['recipes']);
        $this->assertSame($result, Cache::get(app(RecipeCacheKey::class)->make($input)));
        $this->assertSame('Telur Rebus', $result['recipes'][0]['title']);
        Http::assertSentCount(1);
    }

    public function test_strict_honors_allergies_and_never_relabels_missing_ingredients_optional(): void
    {
        $data = $this->recipeData();
        $data['recipes'][0]['ingredients'][] = ['name' => 'udang', 'quantity' => '1', 'optional' => true];
        $this->fakeProvider(['*' => Http::response($this->geminiBody($data))]);
        $result = app(GeminiRecipeService::class)->generateRecipes(['ingredients' => ['telur', 'air'], 'allergies' => 'udang', 'mode' => RecipeMode::STRICT]);
        $this->assertSame([], $result['recipes']);
        Http::assertSentCount(1);
    }

    public function test_checklist_and_alternatives_reject_forged_empty_and_foreign_input(): void
    {
        $snapshot = $this->normal();
        $this->fakeProvider([]);
        $url = route('recommendations.alternatives', $snapshot['id']);
        $this->postJson($url, ['confirmed' => []])->assertUnprocessable();
        $this->postJson($url, ['confirmed' => [hash('sha256', 'bahan asing')]])->assertUnprocessable();
        $this->patchJson(route('recommendations.checklist', $snapshot['id']), ['confirmed' => [hash('sha256', 'telur'), hash('sha256', 'telur')]])->assertUnprocessable();
        $this->patchJson(route('recommendations.checklist', $snapshot['id']), ['confirmed' => []])->assertOk();
        $this->withSession(['recommendation_owner' => 'different-owner']);
        $this->postJson($url, ['confirmed' => [hash('sha256', 'telur')]])->assertNotFound();
        $this->patchJson(route('recommendations.checklist', $snapshot['id']), ['confirmed' => []])->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_strict_invalid_json_remains_safe_and_uncached(): void
    {
        $this->fakeProvider(['*' => Http::response(['candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [['text' => '{bad']]]]]])]);
        $input = ['ingredients' => ['telur'], 'mode' => RecipeMode::STRICT];
        $this->postJson('/rekomendasi', $input)->assertStatus(503)->assertDontSee('fake-only');
        $this->assertNull(Cache::get(app(RecipeCacheKey::class)->make($input)));
        Http::assertSentCount(1);
    }
}
