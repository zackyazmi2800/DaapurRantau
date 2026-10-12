<?php

namespace Tests\Feature;

use App\Exceptions\RecipeGenerationException;
use App\Services\GeminiRecipeService;
use App\Support\RecipeCacheKey;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Tests\Support\GeminiFixture;
use Tests\TestCase;

class GeminiRecipeServiceTest extends TestCase
{
    use GeminiFixture;

    private array $input = ['ingredients' => ['telur', 'air']];

    protected function setUp(): void
    {
        parent::setUp();
        config(['ai.gemini.api_key' => 'test-key-only', 'ai.cache.enabled' => true]);
        Cache::flush();
        Http::preventStrayRequests();
        Sleep::fake();
    }

    private function service(): GeminiRecipeService
    {
        return app(GeminiRecipeService::class);
    }

    private function assertFailure(string $reason, ?array $input = null): void
    {
        try {
            $this->service()->generateRecipes($input ?? $this->input);
            $this->fail('Expected safe generation failure.');
        } catch (RecipeGenerationException $exception) {
            $this->assertSame($reason, $exception->reason);
            $this->assertStringNotContainsString('test-key-only', $exception->getMessage());
        }
        $this->assertNull(Cache::get(app(RecipeCacheKey::class)->make($input ?? $this->input)));
    }

    public function test_cache_miss_generates_valid_json_with_correct_rest_payload(): void
    {
        $this->fakeProvider(['generativelanguage.googleapis.com/*' => Http::response($this->geminiBody())]);
        $this->assertSame($this->recipeData(), $this->service()->generateRecipes($this->input));
        Http::assertSent(function ($request) {
            $generation = $request['generationConfig'];
            $this->assertSame('MINIMAL', $generation['thinkingConfig']['thinkingLevel']);
            $this->assertSame(3000, $generation['maxOutputTokens']);
            $this->assertSame('application/json', $generation['responseMimeType']);
            $this->assertSame(3, $generation['responseJsonSchema']['properties']['recipes']['maxItems']);
            $this->assertSame(3, $generation['responseJsonSchema']['properties']['recipes']['minItems']);
            $this->assertArrayNotHasKey('temperature', $generation);
            $this->assertArrayNotHasKey('topP', $generation);
            $this->assertArrayNotHasKey('topK', $generation);
            $this->assertCount(1, $request['contents']);

            return str_ends_with($request->url(), '/gemini-3.5-flash-lite:generateContent')
                && $request->hasHeader('x-goog-api-key', 'test-key-only') && isset($request['systemInstruction']);
        });
    }

    public function test_generation_schema_is_compact_but_backend_bounds_remain_strict(): void
    {
        $this->fakeProvider(['*' => Http::response($this->geminiBody())]);
        $this->service()->generateRecipes($this->input);
        Http::assertSent(function ($request) {
            $schema = $request['generationConfig']['responseJsonSchema'];
            $this->assertSame(3, $schema['properties']['recipes']['maxItems']);
            $recipe = $schema['properties']['recipes']['items'];
            $this->assertSame($this->service()->buildResponseSchema()['properties']['recipes']['items']['required'], $recipe['required']);
            $this->assertFalse($recipe['additionalProperties']);
            foreach (['cook_time_minutes', 'servings', 'ingredients', 'steps'] as $field) {
                foreach (['minimum', 'maximum', 'minItems', 'maxItems'] as $bound) {
                    $this->assertArrayNotHasKey($bound, $recipe['properties'][$field]);
                }
            }

            return true;
        });
        foreach ([['cook_time_minutes', 0], ['cook_time_minutes', 1441], ['servings', 0], ['servings', 11],
            ['ingredients', []], ['ingredients', array_fill(0, 31, $this->recipeData()['recipes'][0]['ingredients'][0])],
            ['steps', []], ['steps', array_fill(0, 11, 'Langkah')]] as [$field, $value]) {
            Cache::flush();
            $data = $this->recipeData();
            $data['recipes'][0][$field] = $value;
            $this->fakeProvider(['*' => Http::response($this->geminiBody($data))]);
            $this->assertFailure(in_array($field, ['ingredients', 'steps'], true) ? 'invalid_item_count' : 'invalid_schema');
        }
    }

    public function test_cache_hit_with_reordered_ingredients_does_not_call_provider(): void
    {
        $this->fakeProvider(['*' => Http::response($this->geminiBody())]);
        $this->service()->generateRecipes($this->input);
        $this->service()->generateRecipes(['ingredients' => [' AIR ', 'Telur']]);
        Http::assertSentCount(1);
    }

    public function test_cache_can_be_disabled_and_expires(): void
    {
        $this->fakeProvider(['*' => Http::response($this->geminiBody())]);
        config(['ai.cache.ttl' => 2]);
        $this->service()->generateRecipes($this->input);
        $this->travel(3)->seconds();
        $this->service()->generateRecipes($this->input);
        config(['ai.cache.enabled' => false]);
        $this->service()->generateRecipes($this->input);
        Http::assertSentCount(3);
    }

    public function test_invalid_json_empty_truncated_blocked_and_malformed_outputs_are_not_cached(): void
    {
        $invalid = $this->geminiBody();
        $invalid['candidates'][0]['content']['parts'][0]['text'] = '{"recipes":';
        $empty = $this->geminiBody();
        $empty['candidates'][0]['content']['parts'] = [];
        $bad = $this->recipeData();
        unset($bad['recipes'][0]['title']);
        $badInteger = $this->recipeData();
        $badInteger['recipes'][0]['servings'] = '1';
        $badBoolean = $this->recipeData();
        $badBoolean['recipes'][0]['ingredients'][0]['optional'] = 0;
        $tooMany = $this->recipeData();
        $tooMany['recipes'][0]['steps'] = array_fill(0, 11, 'Langkah');
        foreach ([[$invalid, 'invalid_json'], [$empty, 'empty_or_oversized_output'],
            [[], 'empty_candidates'], [$this->geminiBody(finish: 'MAX_TOKENS'), 'incomplete_or_blocked'],
            [['promptFeedback' => ['blockReason' => 'SAFETY']], 'blocked_or_empty'],
            [$this->geminiBody($bad), 'invalid_fields'], [$this->geminiBody($badInteger), 'invalid_schema'],
            [$this->geminiBody($badBoolean), 'invalid_schema'], [$this->geminiBody($tooMany), 'invalid_item_count'],
        ] as [$body, $reason]) {
            $this->fakeProvider(['*' => Http::response($body)]);
            $this->assertFailure($reason);
        }
    }

    public function test_empty_recipe_list_is_rejected(): void
    {
        $data = ['message' => 'Tidak ada resep yang sesuai.', 'recipes' => []];
        $this->fakeProvider(['*' => Http::response($this->geminiBody($data))]);
        $this->assertFailure('invalid_recipe_count');
    }

    public function test_timeout_or_connection_failure_is_not_retried_or_cached(): void
    {
        $calls = 0;
        $this->fakeProvider(function () use (&$calls) {
            $calls++;
            throw new ConnectionException('test-key-only');
        });
        $this->assertFailure('connection_or_timeout');
        $this->assertSame(1, $calls);
    }

    public function test_429_daily_quota_is_not_retried(): void
    {
        $this->fakeProvider(['*' => Http::response(['error' => ['message' => 'daily quota']], 429, ['Retry-After' => '86400'])]);
        $this->assertFailure('provider_quota');
        Http::assertSentCount(1);
    }

    public function test_500_is_retried_once_and_failure_not_cached(): void
    {
        $this->fakeProvider(['*' => Http::response([], 500)]);
        $this->assertFailure('provider_unavailable');
        Http::assertSentCount(2);
    }

    public function test_503_retry_can_recover_and_long_retry_after_is_respected(): void
    {
        $this->fakeProvider(['*' => Http::sequence()->push([], 503, ['Retry-After' => '0'])->push($this->geminiBody())]);
        $this->assertSame($this->recipeData(), $this->service()->generateRecipes($this->input));
        Http::assertSentCount(2);
        Cache::flush();
        $this->fakeProvider(['*' => Http::response([], 503, ['Retry-After' => '60'])]);
        $this->assertFailure('provider_unavailable');
        Http::assertSentCount(1);
    }

    public function test_400_access_and_model_errors_are_safe_and_not_retried(): void
    {
        foreach ([400 => 'invalid_provider_request', 401 => 'provider_access_denied', 403 => 'provider_access_denied', 404 => 'model_unavailable'] as $status => $reason) {
            $this->fakeProvider(['*' => Http::response(['error' => 'test-key-only'], $status)]);
            $this->assertFailure($reason);
            Http::assertSentCount(1);
        }
    }

    public function test_incomplete_token_metadata_and_multiple_parts_are_handled_safely(): void
    {
        Log::spy();
        $body = $this->geminiBody();
        $body['usageMetadata'] = ['promptTokenCount' => 12];
        $json = $body['candidates'][0]['content']['parts'][0]['text'];
        $body['candidates'][0]['content']['parts'] = [['thought' => true, 'text' => 'hidden'], ['text' => substr($json, 0, 20)], ['text' => substr($json, 20)]];
        $this->fakeProvider(['*' => Http::response($body)]);
        $this->service()->generateRecipes($this->input);
        Log::shouldHaveReceived('info')->with('gemini.request', \Mockery::on(fn ($metrics) => $metrics['promptTokenCount'] === 12 && $metrics['totalTokenCount'] === null));
    }

    public function test_missing_key_makes_no_request_and_generation_lock_is_released_after_failure(): void
    {
        config(['ai.gemini.api_key' => '']);
        $this->assertFailure('missing_api_key');
        Http::assertNothingSent();
        $lock = Cache::lock(app(RecipeCacheKey::class)->make($this->input).':lock', 130);
        $this->assertTrue($lock->get());
        $lock->release();
    }

    public function test_generation_lock_prevents_duplicate_requests(): void
    {
        $lock = Cache::lock(app(RecipeCacheKey::class)->make($this->input).':lock', 130);
        $lock->get();
        try {
            $this->assertFailure('generation_in_progress');
            Http::assertNothingSent();
        } finally {
            $lock->release();
        }
    }

    public function test_explicit_allergen_conflict_is_rejected(): void
    {
        $this->fakeProvider(['*' => Http::response($this->geminiBody())]);
        $this->assertFailure('allergen_conflict', $this->input + ['allergies' => 'telur']);
    }

    public function test_corrupt_cache_entry_is_evicted_and_replaced(): void
    {
        Cache::put(app(RecipeCacheKey::class)->make($this->input), ['recipes' => []], 60);
        $this->fakeProvider(['*' => Http::response($this->geminiBody())]);
        $this->assertSame($this->recipeData(), $this->service()->generateRecipes($this->input));
        Http::assertSentCount(1);
    }

    public function test_strict_schema_rejects_wrong_shapes_extra_fields_and_too_many_recipes(): void
    {
        $cases = [];
        $cases[] = ['message' => 'Tidak ada.', 'recipes' => new \stdClass];
        $cases[] = $this->recipeData() + ['secret' => 'extra'];
        $data = $this->recipeData();
        $data['recipes'] = array_fill(0, 4, $data['recipes'][0]);
        $cases[] = $data;
        $data = $this->recipeData();
        $data['recipes'][0]['cook_time_minutes'] = 0;
        $cases[] = $data;
        foreach ($cases as $data) {
            $this->fakeProvider(['*' => Http::response($this->geminiBody($data))]);
            try {
                $this->service()->generateRecipes($this->input);
                $this->fail('Invalid schema accepted.');
            } catch (RecipeGenerationException) {
                $this->assertNull(Cache::get(app(RecipeCacheKey::class)->make($this->input)));
            }
        }
    }

    public function test_missing_metadata_is_allowed_and_blocked_candidate_is_rejected(): void
    {
        $body = $this->geminiBody();
        unset($body['usageMetadata']);
        $this->fakeProvider(['*' => Http::response($body)]);
        $this->assertSame($this->recipeData(), $this->service()->generateRecipes($this->input));
        Cache::flush();
        $body['candidates'][0]['safetyRatings'] = [['blocked' => true]];
        $this->fakeProvider(['*' => Http::response($body)]);
        $this->assertFailure('blocked');
    }

    public function test_logs_only_contain_safe_metrics_for_success_and_cache_hit(): void
    {
        Log::spy();
        $this->fakeProvider(['*' => Http::response($this->geminiBody())]);
        $input = $this->input + ['allergies' => 'sensitive-allergen'];
        $this->service()->generateRecipes($input);
        $this->service()->generateRecipes($input);
        Log::shouldHaveReceived('info')->with('gemini.recipe', \Mockery::on(fn ($metrics) => $metrics['cache'] === 'hit' && $metrics['status'] === 'success'));
        Log::shouldHaveReceived('info')->with(\Mockery::any(), \Mockery::on(fn ($metrics) => ! str_contains(json_encode($metrics), 'test-key-only') && ! str_contains(json_encode($metrics), 'sensitive-allergen')))->times(3);
    }
}
