<?php

use App\Http\Middleware\PrivateWebResponse;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [PrivateWebResponse::class, \App\Http\Middleware\VerifiedAccountSession::class, \Illuminate\Session\Middleware\AuthenticateSession::class, \App\Http\Middleware\GuestIdentity::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['password', 'password_confirmation', 'current_password', 'otp', 'token']);
        $exceptions->render(fn (\App\Exceptions\SqliteBusyException $exception, Request $request) => \App\Support\SqliteContention::response($request));
        $exceptions->dontReport([\App\Exceptions\SqliteBusyException::class]);
        // Also cover contention in session/cache/throttle outside the controller.
        $exceptions->render(function (\Illuminate\Database\QueryException $exception, Request $request) {
            if (\App\Support\SqliteContention::isBusy($exception)) {
                return \App\Support\SqliteContention::response($request);
            }
        });
        $exceptions->report(function (\Illuminate\Database\QueryException $exception) {
            if (\App\Support\SqliteContention::isBusy($exception)) {
                \Illuminate\Support\Facades\Log::warning('database.contention', ['driver' => 'sqlite', 'status' => 'busy']);

                return false; // Never log SQL bindings, session payloads or raw exception.
            }
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
