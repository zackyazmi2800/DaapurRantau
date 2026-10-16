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
        $authLimit = fn (int $count, string $key) => Limit::perMinute($count)->by($key)
            ->response(fn (Request $request) => $request->expectsJson()
                ? response()->json(['message' => 'Terlalu banyak percobaan. Tunggu satu menit.'], 429, ['Retry-After' => '60'])
                : response('Terlalu banyak percobaan. Tunggu satu menit.', 429, ['Retry-After' => '60']));
        RateLimiter::for('auth-login', fn (Request $request) => [
            $authLimit(10, 'login-ip:'.hash('sha256', $request->ip())),
            $authLimit(5, 'login-email:'.hash('sha256', mb_strtolower(trim(is_string($request->input('email')) ? $request->input('email') : '')))),
        ]);
        RateLimiter::for('auth-google', fn (Request $request) => $authLimit(10, 'google:'.hash('sha256', $request->ip())));
        RateLimiter::for('auth-register', fn (Request $request) => $authLimit(3, 'register:'.hash('sha256', $request->ip())));
        RateLimiter::for('auth-otp', fn (Request $request) => $authLimit(5, 'otp:'.hash('sha256', $request->ip())));
        RateLimiter::for('auth-resend', fn (Request $request) => $authLimit(3, 'resend:'.hash('sha256', $request->ip())));
        RateLimiter::for('auth-reset', fn (Request $request) => $authLimit(3, 'reset:'.hash('sha256', $request->ip())));

        RateLimiter::for('recipes', fn (Request $request) => Limit::perMinute(5)
            ->by(hash('sha256', $request->ip()))
            ->response(fn (Request $request, array $headers) => RecipeFailureResponse::make(
                $request, RecipeGenerationException::QUOTA, 429, $headers
            )));
    }
}
