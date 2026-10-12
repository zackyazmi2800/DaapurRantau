<?php

namespace Tests\Feature;

use App\Exceptions\RecipeGenerationException;
use App\Services\GeminiRecipeService;
use App\Support\GeminiErrorDiagnostics;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeminiDiagnosticsTest extends TestCase
{
    public function test_diagnostics_do_not_echo_credentials_or_user_data(): void
    {
        $response = new Response(new \GuzzleHttp\Psr7\Response(400, [], json_encode(['error' => [
            'code' => 400, 'status' => 'INVALID_ARGUMENT',
            'message' => 'Request contains an invalid argument. SECRET allergy-private responseJsonSchema',
            'details' => [['@type' => 'type.googleapis.com/google.rpc.BadRequest', 'fieldViolations' => [['description' => 'SECRET']]]],
        ]])));
        $context = GeminiErrorDiagnostics::context($response);
        $this->assertSame(400, $context['http_status']);
        $this->assertSame(['responseJsonSchema'], $context['referenced_fields']);
        $this->assertStringNotContainsString('SECRET', json_encode($context));
        $this->assertStringNotContainsString('allergy-private', json_encode($context));
    }

    public function test_quota_on_503_is_not_retried(): void
    {
        config(['ai.gemini.api_key' => 'fake', 'ai.cache.enabled' => false]);
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['error' => ['status' => 'RESOURCE_EXHAUSTED']], 503)]);
        try {
            app(GeminiRecipeService::class)->generateRecipes(['ingredients' => ['telur']]);
            $this->fail('Expected quota failure');
        } catch (RecipeGenerationException $e) {
            $this->assertSame(429, $e->status);
            $this->assertSame(RecipeGenerationException::QUOTA, $e->getMessage());
        }
        Http::assertSentCount(1);
    }

    public function test_outage_message_is_safe(): void
    {
        $this->assertSame(RecipeGenerationException::OUTAGE, (new RecipeGenerationException('provider_unavailable'))->getMessage());
    }
}
