<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class VerifiedAccountSession
{
    public function handle(Request $request, Closure $next): Response
    {
        // Defense in depth: an unverified session must never bypass guest quota.
        if ($request->user() && ! $request->user()->email_verified_at) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }
        return $next($request);
    }
}
