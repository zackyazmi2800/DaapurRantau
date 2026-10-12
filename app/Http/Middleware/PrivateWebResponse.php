<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PrivateWebResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        // Web pages can contain session-owned recipes, CSRF tokens and old input.
        // Static Vite assets are served independently and remain cacheable.
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Referrer-Policy', 'same-origin');

        return $response;
    }
}
