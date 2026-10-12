<?php

namespace Tests\Feature;

use App\Services\RecipeImageService;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\RejectedPromise;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\GeminiFixture;
use Tests\Support\UnsplashFixture;
use Tests\TestCase;

class RecipeImageBatchTest extends TestCase
{
    use GeminiFixture, UnsplashFixture;

    private array $recipes = [['title' => 'Nasi Goreng Telur'], ['title' => 'Mie Goreng'], ['title' => 'Telur Dadar']];

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.unsplash.access_key' => 'batch-test-secret']);
        Cache::flush();
        Http::preventStrayRequests();
    }

    private function body(string $query): array
    {
        $id = match ($query) {
            'nasi goreng telur' => 'rice', 'mie goreng' => 'noodles', 'telur dadar' => 'omelette',
        };
        $photo = $this->photo($query, $id);
        $photo['urls']['small'] = 'https://images.unsplash.com/'.$id.'?w=400';
        $photo['urls']['regular'] = 'https://images.unsplash.com/'.$id.'?w=1080';

        return ['results' => [$photo]];
    }

    public function test_three_requests_start_before_responses_resolve_and_results_keep_recipe_order(): void
    {
        $pending = [];
        $this->fakeProvider(function ($request) use (&$pending) {
            $this->assertSame('api.unsplash.com', parse_url($request->url(), PHP_URL_HOST));
            $this->assertSame('/search/photos', parse_url($request->url(), PHP_URL_PATH));
            $this->assertTrue($request->hasHeader('Authorization', 'Client-ID batch-test-secret'));
            $this->assertSame(5, (int) $request['per_page']);
            $this->assertSame('landscape', $request['orientation']);
            $promise = new Promise;
            $pending[] = [$promise, $this->body($request['query'])];
            // No response is available until all three requests have been dispatched.
            if (count($pending) === 3) {
                foreach (array_reverse($pending) as [$item, $body]) {
                    $item->resolve(new Response(200, [], json_encode($body)));
                }
            }

            return $promise;
        });
        // Laravel fake's transfer-stat hook waits synchronously. Disable only that
        // test hook so pending fake promises can exercise actual pool scheduling.
        Http::globalMiddleware(fn ($handler) => function ($request, $options) use ($handler) {
            unset($options['on_stats']);

            return $handler($request, $options);
        });
        $images = app(RecipeImageService::class)->getImagesForRecipes($this->recipes);
        $this->assertSame(['rice', 'noodles', 'omelette'], array_column($images, 'unsplash_photo_id'));
        Http::assertSentCount(3);
        $this->assertSame($images, app(RecipeImageService::class)->getImagesForRecipes($this->recipes));
        Http::assertSentCount(3);
    }

    public function test_mixed_cache_only_fetches_missing_titles_and_negative_cache_is_respected(): void
    {
        $service = app(RecipeImageService::class);
        $this->fakeProvider(fn ($request) => Http::response($this->body($request['query'])));
        $cached = $service->getImagesForRecipes(array_slice($this->recipes, 0, 2));
        $this->fakeProvider(fn ($request) => Http::response($this->body($request['query'])));
        $images = $service->getImagesForRecipes($this->recipes);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['query'] === 'telur dadar');
        $this->assertSame($cached, array_slice($images, 0, 2));
        Cache::put($service->cacheKey('Mie Goreng'), $service->getPlaceholder(), 3600);
        $this->fakeProvider([]);
        $images = $service->getImagesForRecipes($this->recipes);
        $this->assertSame('placeholder', $images[1]['image_source']);
        Http::assertNothingSent();
    }

    public function test_one_cached_recipe_leaves_two_requests_and_remaining_quota_limits_batch(): void
    {
        $service = app(RecipeImageService::class);
        $this->fakeProvider(fn ($request) => Http::response($this->body($request['query'])));
        $service->getRecipeImage('Nasi Goreng Telur');
        $this->fakeProvider(fn ($request) => Http::response($this->body($request['query'])));
        $images = $service->getImagesForRecipes($this->recipes);
        $this->assertSame(['rice', 'noodles', 'omelette'], array_column($images, 'unsplash_photo_id'));
        Http::assertSentCount(2);
        Http::assertNotSent(fn ($request) => $request['query'] === 'nasi goreng telur');
        Cache::flush();
        $scope = 'unsplash:'.hash('sha256', 'batch-test-secret');
        Cache::put($scope.':remaining', 3, 3600);
        $this->fakeProvider(fn ($request) => Http::response($this->body($request['query'])));
        $service->getImagesForRecipes($this->recipes);
        Http::assertSentCount(1);
        $this->assertSame(2, Cache::get($scope.':remaining'));
    }

    public function test_one_failure_does_not_discard_other_responses_including_timeout_and_quota(): void
    {
        foreach ([500, 429, 'timeout', 'irrelevant'] as $failure) {
            Cache::flush();
            $this->fakeProvider(function ($request) use ($failure) {
                if ($request['query'] !== 'mie goreng') {
                    return Http::response($this->body($request['query']));
                }

                return match ($failure) {
                    'timeout' => new RejectedPromise(new ConnectionException('test-only')),
                    'irrelevant' => Http::response(['results' => [$this->photo('A forest')]]),
                    default => Http::response([], $failure),
                };
            });
            $service = app(RecipeImageService::class);
            $images = $service->getImagesForRecipes($this->recipes);
            $this->assertSame(['unsplash', 'placeholder', 'unsplash'], array_column($images, 'image_source'));
            $this->assertSame(['rice', null, 'omelette'], array_column($images, 'unsplash_photo_id'));
            $this->assertSame('placeholder', Cache::get($service->cacheKey('Mie Goreng'))['image_source']);
            $this->fakeProvider([]);
            $this->assertSame($images, $service->getImagesForRecipes($this->recipes));
            Http::assertNothingSent();
        }
    }

    public function test_batch_respects_remaining_budget_and_deduplicates_same_normalized_title(): void
    {
        $scope = 'unsplash:'.hash('sha256', 'batch-test-secret');
        Cache::put($scope.':budget', 44, 3600);
        $this->fakeProvider(fn ($request) => Http::response($this->body($request['query'])));
        $images = app(RecipeImageService::class)->getImagesForRecipes($this->recipes);
        $this->assertSame(['unsplash', 'placeholder', 'placeholder'], array_column($images, 'image_source'));
        Http::assertSentCount(1);
        Cache::flush();
        $this->fakeProvider(fn ($request) => Http::response($this->body($request['query'])));
        $images = app(RecipeImageService::class)->getImagesForRecipes([['title' => 'Mie Goreng'], ['title' => ' MIE   GORENG ']]);
        $this->assertSame($images[0], $images[1]);
        Http::assertSentCount(1);
    }

    public function test_more_than_three_recipes_are_rejected_without_http(): void
    {
        $this->fakeProvider([]);
        try {
            app(RecipeImageService::class)->getImagesForRecipes(array_merge($this->recipes, [$this->recipes[0]]));
            $this->fail('Unbounded batch accepted.');
        } catch (\InvalidArgumentException) {
            Http::assertNothingSent();
        }
    }
}
