<?php

namespace Tests\Feature;

use App\Exceptions\RecipeGenerationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\GeminiFixture;
use Tests\TestCase;

class GeminiRecommendationTest extends TestCase
{
    use GeminiFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ai.gemini.api_key' => 'secret-never-render', 'ai.cache.enabled' => true]);
        Cache::flush();
        Http::preventStrayRequests();
        $this->fakeProvider(['*' => Http::response($this->geminiBody())]);
    }

    public function test_valid_input_returns_recipes_in_html_and_json_without_exposing_key(): void
    {
        $this->post('/rekomendasi', ['ingredients' => ['telur']])->assertOk()
            ->assertSee('Telur Rebus')->assertDontSee('secret-never-render');
        $this->postJson('/rekomendasi', ['ingredients' => ['telur']])->assertOk()
            ->assertJsonPath('recipes.0.title', 'Telur Rebus')->assertDontSee('secret-never-render');
        Http::assertSentCount(1);
    }

    public function test_empty_input_never_calls_provider(): void
    {
        $this->postJson('/rekomendasi', [])->assertUnprocessable()->assertJsonValidationErrors('ingredients');
        Http::assertNothingSent();
    }

    public function test_sixth_request_is_rate_limited_even_on_cache_hit(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/rekomendasi', ['ingredients' => ['telur']])->assertOk();
        }
        $this->postJson('/rekomendasi', ['ingredients' => ['telur']])->assertStatus(429)
            ->assertJsonPath('message', RecipeGenerationException::QUOTA)->assertHeader('Retry-After');
        Http::assertSentCount(1);
    }

    public function test_concurrent_requests_by_same_ip_are_rejected(): void
    {
        $lock = Cache::lock('recipe-visitor:'.hash('sha256', '127.0.0.1'), 130);
        $lock->get();
        try {
            $this->postJson('/rekomendasi', ['ingredients' => ['telur']])->assertStatus(429);
            Http::assertNothingSent();
        } finally {
            $lock->release();
        }
    }

    public function test_provider_error_is_safe_and_keeps_input_for_retry(): void
    {
        $this->fakeProvider(['*' => Http::response(['error' => 'secret-never-render'], 403)]);
        $this->post('/rekomendasi', ['ingredients' => ['telur'], 'allergies' => 'udang'])->assertStatus(503)
            ->assertSee('Maaf, rekomendasi belum tersedia. Silakan coba lagi.')->assertDontSee('secret-never-render')
            ->assertSessionHasInput('ingredients', ['telur']);
    }

    public function test_quota_error_is_translated(): void
    {
        $this->fakeProvider(['*' => Http::response([], 429)]);
        $this->postJson('/rekomendasi', ['ingredients' => ['telur']])->assertStatus(429)
            ->assertJsonPath('message', RecipeGenerationException::QUOTA);
    }

    public function test_provider_html_is_escaped_in_blade(): void
    {
        $data = $this->recipeData();
        $data['recipes'][0]['title'] = '<script>alert(1)</script>';
        $this->fakeProvider(['*' => Http::response($this->geminiBody($data))]);
        $this->post('/rekomendasi', ['ingredients' => ['telur']])->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
    }
}
