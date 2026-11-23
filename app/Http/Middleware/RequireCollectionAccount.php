<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireCollectionAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user() || ! $request->user()->email_verified_at) {
            return $request->expectsJson()
                ? response()->json(['message' => 'Favorit dan riwayat memerlukan akun.', 'account_url' => route('account.required')], 401)
                : response()->view('account.required', [], 401);
        }

        if (! app(\App\Services\SubscriptionService::class)->active($request->user()->id)) {
            return $request->expectsJson()
                ? response()->json(['message' => 'Favorit dan riwayat memerlukan Premium.', 'pricing_url' => route('pricing')], 403)
                : response()->view('subscription.required', [], 403);
        }

        return $next($request);
    }
}
