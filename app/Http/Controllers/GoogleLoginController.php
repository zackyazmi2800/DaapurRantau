<?php

namespace App\Http\Controllers;

use App\Exceptions\GoogleLoginException;
use App\Services\GoogleLoginService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Two\InvalidStateException;

class GoogleLoginController extends Controller
{
    public function redirect(Request $request, GoogleLoginService $google)
    {
        try {
            if (! $google->configured()) { throw new GoogleLoginException(GoogleLoginException::UNAVAILABLE); }
            $request->session()->regenerate();
            $response = $google->provider()->redirect();
            $request->session()->put('google_oauth_started_at', now()->timestamp);
            return $response;
        } catch (\Throwable) {
            $request->session()->forget(['state', 'google_oauth_started_at']);
            return redirect()->route('login')->withErrors(['google' => GoogleLoginException::UNAVAILABLE]);
        }
    }

    public function callback(Request $request, GoogleLoginService $google)
    {
        try {
            if (! $google->configured()) { throw new GoogleLoginException(GoogleLoginException::UNAVAILABLE); }
            $started = $request->session()->pull('google_oauth_started_at');
            $state = $request->query('state');
            $expected = $request->session()->get('state');
            if (! is_int($started) || $started > now()->timestamp || $started < now()->subMinutes(10)->timestamp
                || ! is_string($state) || ! is_string($expected) || $expected === '' || ! hash_equals($expected, $state)) {
                throw new InvalidStateException;
            }
            if ($request->query('error') !== null) {
                throw new GoogleLoginException('Login Google dibatalkan atau tidak diizinkan. Kamu dapat mencoba kembali.');
            }
            if (! is_string($request->query('code')) || $request->query('code') === '' || strlen($request->query('code')) > 4096) {
                throw new GoogleLoginException(GoogleLoginException::FAILED);
            }
            $user = $google->resolveUser();
            Auth::login($user);
            $request->session()->regenerate();
            $request->session()->put('password_hash_'.config('auth.defaults.guard'), Auth::guard()->hashPasswordForCookie($user->getAuthPassword()));
            $request->session()->forget(['pending_verification_user', 'pending_verification_until']);
            // dapur_guest cookie and quota ledger are deliberately untouched.
            return redirect()->route('home')->with('status', 'Berhasil masuk dengan Google. Selamat memasak!');
        } catch (GoogleLoginException $exception) {
            return redirect()->route('login')->withErrors(['google' => $exception->getMessage()]);
        } catch (InvalidStateException) {
            return redirect()->route('login')->withErrors(['google' => 'Sesi Google tidak valid atau sudah kedaluwarsa. Mulai kembali dari halaman masuk.']);
        } catch (\Throwable) {
            // Do not report raw provider/SQL exceptions: they may contain tokens or PII.
            return redirect()->route('login')->withErrors(['google' => GoogleLoginException::FAILED]);
        } finally {
            $request->session()->forget(['state', 'google_oauth_started_at']);
        }
    }
}
