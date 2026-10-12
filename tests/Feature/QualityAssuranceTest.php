<?php

namespace Tests\Feature;

use App\Models\SavedRecipe;
use App\Services\SavedRecipeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Support\GeminiFixture;
use Tests\TestCase;

class QualityAssuranceTest extends TestCase
{
    use GeminiFixture;

    public function test_private_pages_are_not_stored_by_http_caches(): void
    {
        Http::preventStrayRequests();
        foreach (['/', '/favorit', '/riwayat'] as $path) {
            $response = $this->get($path)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff')
                ->assertHeader('X-Frame-Options', 'SAMEORIGIN')->assertHeader('Referrer-Policy', 'same-origin');
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
            $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        }
    }

    public function test_production_error_does_not_expose_exception_or_key(): void
    {
        config(['app.debug' => false, 'ai.gemini.api_key' => 'private-test-key']);
        Route::middleware('web')->get('/qa-failure', fn () => throw new \RuntimeException('private-test-key internal sql details'));
        $this->get('/qa-failure')->assertStatus(500)->assertSee('Maaf, layanan sedang mengalami gangguan.')
            ->assertDontSee('private-test-key')->assertDontSee('internal sql details')->assertDontSee('Stack trace');
        $this->getJson('/qa-failure')->assertStatus(500)->assertDontSee('private-test-key');
    }

    public function test_favorite_status_query_only_requests_displayed_fingerprints(): void
    {
        $visitor = (string) Str::uuid();
        $service = app(SavedRecipeService::class);
        $recipe = $this->recipeData()['recipes'][0];
        $saved = $service->save($visitor, $recipe);
        $other = $recipe;
        $other['title'] = 'Resep lain';
        $service->save($visitor, $other);
        DB::enableQueryLog();
        $marked = $service->mark($visitor, [$recipe]);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertSame($saved->id, $marked[0]['favorite_id']);
        $query = collect($queries)->last();
        $this->assertStringContainsString('recipe_fingerprint', $query['query']);
        $this->assertContains($service->fingerprint($recipe), $query['bindings']);
        $this->assertNotContains($service->fingerprint($other), $query['bindings']);
        $this->assertSame(2, SavedRecipe::count());
    }
}
