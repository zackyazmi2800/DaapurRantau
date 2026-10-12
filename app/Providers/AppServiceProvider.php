<?php

namespace App\Providers;

use App\Exceptions\RecipeGenerationException;
use App\Support\RecipeFailureResponse;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('recipes', fn (Request $request) => Limit::perMinute(5)
            ->by(hash('sha256', $request->ip()))
            ->response(fn (Request $request, array $headers) => RecipeFailureResponse::make(
                $request, RecipeGenerationException::QUOTA, 429, $headers
            )));
    }
}
