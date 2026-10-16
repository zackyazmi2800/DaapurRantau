<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Response;

class GuestIdentity
{
    public function handle(Request $request, Closure $next): Response
    {
        // EncryptCookies authenticates this cookie. It is independent of the login session.
        $token = $request->cookie('dapur_guest');
        $known = is_string($token) && preg_match('/\A[a-f0-9]{64}\z/', $token);
        if (! $known) {
            $token = bin2hex(random_bytes(32));
        }
        $request->attributes->set('guest_identity_hash', hash('sha256', $token));
        Cookie::queue(cookie('dapur_guest', $token, 60 * 24 * 365, '/', null, $request->isSecure(), true, false, 'lax'));

        // Require a browser round-trip before generation: never charge an identity
        // whose cookie has not reached the browser (including first-request races).
        if (! $known && ! $request->user() && $request->isMethod('POST')
            && $request->routeIs('recommendations.store', 'recommendations.alternatives')) {
            $message = 'Aktifkan cookie, buka kembali form, lalu kirim pencarianmu.';

            return $request->expectsJson()
                ? response()->json(['message' => $message], 409)
                : response()->view('recommendations.operation-error', compact('message'), 409);
        }

        return $next($request);
    }
}
