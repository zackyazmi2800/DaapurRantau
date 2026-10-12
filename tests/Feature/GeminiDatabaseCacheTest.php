<?php

namespace Tests\Feature;

use App\Services\GeminiRecipeService;
use App\Support\RecipeCacheKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\GeminiFixture;
use Tests\TestCase;

class GeminiDatabaseCacheTest extends TestCase
{
    use GeminiFixture, RefreshDatabase;

    public function test_database_cache_and_atomic_lock_use_existing_migrations(): void
    {
        config(['cache.default' => 'database', 'ai.gemini.api_key' => 'fake-key', 'ai.cache.enabled' => true]);
        $this->fakeProvider(['*' => Http::response($this->geminiBody())]);
        $input = ['ingredients' => ['telur', 'nasi', 'cabai']];
        $service = app(GeminiRecipeService::class);
        $result = $service->generateRecipes($input);
        Http::assertSentCount(1);
        $this->fakeProvider(fn () => throw new \RuntimeException('Cache hit must not send HTTP'));
        $this->assertSame($result, $service->generateRecipes(['ingredients' => ['cabai', 'telur', 'nasi']]));
        Http::assertNothingSent();
        $key = app(RecipeCacheKey::class)->make($input);
        $this->assertDatabaseHas('cache', ['key' => config('cache.prefix').$key]);
        $first = Cache::lock($key.':lock', 130);
        $second = Cache::lock($key.':lock', 130);
        $this->assertTrue($first->get());
        $this->assertFalse($second->get());
        $first->release();
        $this->assertTrue($second->get());
        $second->release();
    }
}
