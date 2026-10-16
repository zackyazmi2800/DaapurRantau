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

        return $next($request);
    }
}
