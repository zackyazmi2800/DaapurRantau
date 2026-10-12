<?php

namespace App\Http\Middleware;

use App\Exceptions\RecipeGenerationException;
use App\Support\RecipeFailureResponse;
use Closure;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class LimitConcurrentRecipes
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            if (! (Cache::getStore() instanceof LockProvider)) {
                return RecipeFailureResponse::make($request, RecipeGenerationException::UNAVAILABLE, 503);
            }
            $lock = Cache::lock('recipe-visitor:'.hash('sha256', $request->ip()), 130);
            if (! $lock->get()) {
                return RecipeFailureResponse::make($request, RecipeGenerationException::QUOTA, 429, ['Retry-After' => '5']);
            }
        } catch (Throwable) {
            Log::warning('gemini.concurrency', ['status' => 'cache_unavailable']);

            return RecipeFailureResponse::make($request, RecipeGenerationException::UNAVAILABLE, 503);
        }
        try {
            return $next($request);
        } finally {
            try {
                $lock->release();
            } catch (Throwable) {
                Log::warning('gemini.concurrency', ['status' => 'lock_release_failed']);
            }
        }
    }
}
