<?php

namespace Tests\Feature;

use App\Models\RecipeSearch;
use App\Models\SavedRecipe;
use App\Services\SearchHistoryService;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\GeminiFixture;
use Tests\TestCase;

class VisitorCollectionsTest extends TestCase
{
    use GeminiFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ai.gemini.api_key' => 'test-only']);
        Cache::flush();
        $this->fakeProvider(['*' => Http::response($this->geminiBody())]);
    }

    private function search(): array
    {
        return $this->post('/rekomendasi', ['ingredients' => ['telur', 'air'], 'servings' => 2, 'preference' => 'hemat', 'allergies' => 'udang'])
            ->assertOk()->viewData('snapshot');
    }

    private function save(array $snapshot): void
    {
        $this->post(route('favorites.store', [$snapshot['id'], $snapshot['recipes'][0]['id']]), ['recipe_data' => ['title' => 'Tampered'], 'visitor_id' => (string) Str::uuid()])
            ->assertRedirect();
    }

    public function test_save_is_server_sourced_deduplicated_and_detail_survives_cache_expiry(): void
    {
        $snapshot = $this->search();
        $this->save($snapshot);
        $this->save($snapshot);
        $this->assertDatabaseCount('saved_recipes', 1);
        $saved = SavedRecipe::first();
        $this->assertSame('Telur Rebus', $saved->recipe_title);
        $this->assertSame(session('visitor_id'), $saved->visitor_id);
        $this->assertTrue(Str::isUuid(session('visitor_id')));
        $this->assertArrayNotHasKey('match', $saved->recipe_data);
        $this->get(route('recommendations.detail', [$snapshot['id'], $snapshot['recipes'][0]['id']]))->assertSee('Batalkan Favorit');
        Cache::flush();
        $this->fakeProvider(fn () => throw new \RuntimeException('No API for favorites'));
        $this->get('/favorit')->assertOk()->assertSee('Telur Rebus');
        $this->get('/favorit/'.$saved->id)->assertOk()->assertSee('Telur Rebus')->assertSee('Periksa persediaanmu');
        Http::assertNothingSent();
        $this->delete('/favorit/'.$saved->id)->assertRedirect('/favorit');
        $this->assertDatabaseCount('saved_recipes', 0);
        $this->get('/favorit')->assertSee('Belum ada resep favorit nih!');
    }

    public function test_new_and_cached_searches_are_recorded_without_allergy_data(): void
    {
        $snapshot = $this->search();
        $this->search();
        Http::assertSentCount(1);
        $this->assertDatabaseCount('recipe_searches', 2);
        $row = RecipeSearch::first();
        $this->assertStringNotContainsString('udang', $row->toJson());
        $this->assertSame(3, $row->recipe_count);
        $this->get('/riwayat')->assertOk()->assertSee('Buka Hasil')->assertSee('Hemat');
        $this->get('/riwayat/'.$row->id.'/ulang')->assertRedirect(route('home').'#cari-resep')
            ->assertSessionHasInput('ingredients', ['telur', 'air'])->assertSessionHasInput('preference', 'hemat')
            ->assertSessionHasInput('servings', 2);
        $this->assertNull(session()->getOldInput('allergies'));
        $this->assertArrayHasKey('allergies', session()->getOldInput());
        $this->travel(61)->minutes();
        $this->get('/riwayat')->assertOk()->assertDontSee('Buka Hasil')->assertSee('Hasil lama sudah tidak tersedia.');
        $this->assertDatabaseCount('recipe_searches', 2);
    }

    public function test_other_session_cannot_access_modify_or_delete_collections_or_save_snapshot(): void
    {
        $snapshot = $this->search();
        $this->save($snapshot);
        $saved = SavedRecipe::first();
        $history = RecipeSearch::first();
        $this->flushSession();
        $this->get('/favorit')->assertDontSee('Telur Rebus');
        $this->get('/riwayat')->assertDontSee('Buka Hasil');
        $this->get('/favorit/'.$saved->id)->assertNotFound();
        $this->delete('/favorit/'.$saved->id)->assertNotFound();
        $this->get('/riwayat/'.$history->id.'/ulang')->assertNotFound();
        $this->delete('/riwayat/'.$history->id)->assertNotFound();
        $this->delete('/riwayat', ['confirm' => '1'])->assertRedirect('/riwayat');
        $this->post(route('favorites.store', [$snapshot['id'], $snapshot['recipes'][0]['id']]))->assertStatus(410);
        $this->assertDatabaseCount('saved_recipes', 1);
        $this->assertDatabaseCount('recipe_searches', 1);
    }

    public function test_same_recipe_can_be_saved_by_two_visitors_without_sharing_ownership(): void
    {
        $this->save($this->search());
        $firstOwner = session('visitor_id');
        $this->flushSession();
        $this->save($this->search());
        $this->assertNotSame($firstOwner, session('visitor_id'));
        $this->assertDatabaseCount('saved_recipes', 2);
        $this->assertSame(1, SavedRecipe::where('visitor_id', session('visitor_id'))->count());
        $this->assertSame(1, SavedRecipe::query()->distinct()->count('recipe_fingerprint'));
        Http::assertSentCount(1);
    }

    public function test_failed_generation_does_not_record_history(): void
    {
        $this->fakeProvider(['*' => Http::response([], 400)]);
        $this->postJson('/rekomendasi', ['ingredients' => ['telur']])->assertStatus(503);
        $this->assertDatabaseCount('recipe_searches', 0);
    }

    public function test_individual_and_bulk_history_deletion_require_confirmation_for_bulk(): void
    {
        $this->search();
        $this->search();
        $this->delete('/riwayat/'.RecipeSearch::first()->id)->assertRedirect('/riwayat');
        $this->assertDatabaseCount('recipe_searches', 1);
        $this->deleteJson('/riwayat')->assertUnprocessable()->assertJsonValidationErrors('confirm');
        $this->assertDatabaseCount('recipe_searches', 1);
        $this->delete('/riwayat', ['confirm' => '1'])->assertRedirect('/riwayat');
        $this->assertDatabaseCount('recipe_searches', 0);
    }

    public function test_history_keeps_only_thirty_latest_for_each_visitor(): void
    {
        $snapshot = $this->search();
        $visitor = session('visitor_id');
        $other = (string) Str::uuid();
        app(SearchHistoryService::class)->record($other, $snapshot);
        for ($i = 0; $i < 32; $i++) {
            app(SearchHistoryService::class)->record($visitor, $snapshot);
        }
        $this->assertSame(30, RecipeSearch::where('visitor_id', $visitor)->count());
        $this->assertSame(1, RecipeSearch::where('visitor_id', $other)->count());
    }

    public function test_invalid_input_expired_and_wrong_recipe_cannot_be_saved(): void
    {
        $this->postJson('/rekomendasi', [])->assertUnprocessable();
        $this->assertDatabaseCount('recipe_searches', 0);
        $snapshot = $this->search();
        $this->post(route('favorites.store', [$snapshot['id'], 'wrong']))->assertNotFound();
        $this->travel(61)->minutes();
        $this->post(route('favorites.store', [$snapshot['id'], $snapshot['recipes'][0]['id']]))->assertStatus(410);
        $this->assertDatabaseCount('saved_recipes', 0);
    }

    public function test_csrf_is_enforced_on_save_and_delete_when_test_bypass_is_disabled(): void
    {
        $snapshot = $this->search();
        $this->app->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        $this->post(route('favorites.store', [$snapshot['id'], $snapshot['recipes'][0]['id']]))->assertStatus(419);
        $this->delete('/riwayat', ['confirm' => '1'])->assertStatus(419);
        $this->withSession(['_token' => 'valid-csrf'])->post(route('favorites.store', [$snapshot['id'], $snapshot['recipes'][0]['id']]), ['_token' => 'valid-csrf'])->assertRedirect();
        $this->delete('/favorit/'.SavedRecipe::first()->id)->assertStatus(419);
        $this->assertDatabaseCount('saved_recipes', 1);
    }
}
