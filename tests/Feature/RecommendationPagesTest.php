<?php

namespace Tests\Feature;

use App\Exceptions\RecipeGenerationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\GeminiFixture;
use Tests\TestCase;

class RecommendationPagesTest extends TestCase
{
    use GeminiFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ai.gemini.api_key' => 'not-for-frontend']);
        Cache::flush();
        $this->fakeProvider(['*' => Http::response($this->geminiBody())]);
    }

    private function search(): array
    {
        return $this->post('/rekomendasi', ['ingredients' => ['telur', 'air']])->assertOk()->viewData('snapshot');
    }

    public function test_results_detail_and_return_do_not_call_gemini_again(): void
    {
        $snapshot = $this->search();
        Http::assertSentCount(1);
        $this->fakeProvider(fn () => throw new \RuntimeException('Unexpected Gemini request'));
        $this->get(route('recommendations.show', $snapshot['id']))->assertOk()->assertSee('Paling Cocok');
        $this->get(route('recommendations.detail', [$snapshot['id'], $snapshot['recipes'][0]['id']]))
            ->assertOk()->assertSee('Telur Rebus')->assertSee('Bahan yang diperlukan')
            ->assertSee('Langkah memasak')->assertSee('type="checkbox"', false)->assertSee('Simpan Favorit')->assertDontSee('not-for-frontend');
        Http::assertNothingSent();
        $this->get(route('recommendations.edit', $snapshot['id']))->assertRedirect(route('home').'#cari-resep')
            ->assertSessionHasInput('ingredients', ['telur', 'air']);
    }

    public function test_other_visitor_cannot_read_or_edit_snapshot_even_with_known_identifiers(): void
    {
        $snapshot = $this->search();
        $this->flushSession();
        foreach ([route('recommendations.show', $snapshot['id']), route('recommendations.edit', $snapshot['id']),
            route('recommendations.detail', [$snapshot['id'], $snapshot['recipes'][0]['id']])] as $url) {
            $this->get($url)->assertStatus(410)->assertDontSee('Telur Rebus');
        }
        $this->withSession(['recommendation_owner' => 'someone-else'])->get(route('recommendations.show', $snapshot['id']))->assertStatus(410);
        Http::assertSentCount(1);
    }

    public function test_expired_missing_and_wrong_recipe_ids_are_handled_without_generation(): void
    {
        $snapshot = $this->search();
        $this->fakeProvider(fn () => throw new \RuntimeException('Unexpected Gemini request'));
        $this->get(route('recommendations.detail', [$snapshot['id'], 'wrong']))->assertNotFound();
        $this->get('/rekomendasi/unknown')->assertStatus(410);
        $this->travel(61)->minutes();
        $this->get(route('recommendations.show', $snapshot['id']))->assertStatus(410)
            ->assertSee('Rekomendasi ini sudah tidak tersedia. Yuk, cari resep lagi!');
        $this->get(route('recommendations.detail', [$snapshot['id'], $snapshot['recipes'][0]['id']]))->assertStatus(410);
        Http::assertNothingSent();
    }

    public function test_recipe_identifier_cannot_be_used_under_another_search(): void
    {
        $a = $this->search();
        $b = $this->search();
        $this->get(route('recommendations.detail', [$a['id'], $b['recipes'][0]['id']]))->assertNotFound();
    }

    public function test_empty_recipes_show_actionable_empty_state(): void
    {
        $this->fakeProvider(['*' => Http::response($this->geminiBody(['message' => 'Tidak ada.', 'recipes' => []]))]);
        $this->post('/rekomendasi', ['ingredients' => ['telur']])->assertStatus(503)
            ->assertSee(RecipeGenerationException::INCOMPLETE);
    }

    public function test_ready_recipes_are_first_and_missing_required_items_are_shown(): void
    {
        $data = $this->recipeData();
        $ready = $data['recipes'][0];
        $ready['title'] = 'Menu Siap';
        $ready['ingredients'][1]['optional'] = true;
        $ready['steps'][0]['instruction'] = 'Masak telur pada wajan antilengket hingga matang.';
        $data['recipes'][1] = $data['recipes'][0];
        $data['recipes'][0] = $ready;
        $this->fakeProvider(['*' => Http::response($this->geminiBody($data))]);
        $response = $this->post('/rekomendasi', ['ingredients' => ['telur']])->assertOk()
            ->assertSeeInOrder(['Menu Siap', 'Telur Rebus'])->assertSee('Butuh 1 bahan tambahan')->assertSee('air');
        $snapshot = $response->viewData('snapshot');
        $this->get(route('recommendations.detail', [$snapshot['id'], $snapshot['recipes'][0]['id']]))->assertSee('Belum tersedia · Opsional');
    }

    public function test_alias_allergen_recipe_is_filtered_from_recommendations(): void
    {
        $data = $this->recipeData();
        $data['recipes'][0]['ingredients'][] = ['name' => 'garlic', 'quantity' => '1 siung', 'optional' => true];
        $this->fakeProvider(['*' => Http::response($this->geminiBody($data))]);
        $this->post('/rekomendasi', ['ingredients' => ['telur'], 'allergies' => 'bawang putih'])->assertStatus(503)
            ->assertDontSee('Telur Rebus')->assertSee(RecipeGenerationException::INCOMPLETE);
        $this->postJson('/rekomendasi', ['ingredients' => ['telur'], 'allergies' => 'bawang putih'])->assertStatus(503)->assertJsonMissingPath('recipes');
    }

    public function test_html_is_escaped_on_detail_and_snapshot_is_server_side(): void
    {
        $data = $this->recipeData();
        $attack = '<img src=x onerror=alert(1)>';
        $data['recipes'][0]['tips'] = $attack;
        $this->fakeProvider(['*' => Http::response($this->geminiBody($data))]);
        $snapshot = $this->search();
        $url = route('recommendations.detail', [$snapshot['id'], $snapshot['recipes'][0]['id']]);
        $this->assertStringNotContainsString('Telur', $url);
        $this->assertStringNotContainsString('Telur Rebus', json_encode(session()->all()));
        foreach ($snapshot['recipes'] as &$recipe) {
            unset($recipe['favorite_id']);
        }
        unset($recipe);
        $this->assertSame($snapshot, Cache::get('recommendation-snapshot:'.$snapshot['id']));
        $this->get($url)->assertOk()->assertDontSee($attack, false)->assertSee(e($attack), false);
    }
}
