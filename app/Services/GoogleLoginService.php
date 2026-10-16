<?php

namespace App\Services;

use App\Exceptions\GoogleLoginException;
use App\Models\SocialAccount;
use App\Models\User;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;

class GoogleLoginService
{
    public function configured(): bool
    {
        $config = config('services.google', []);
        if (! is_string($config['client_id'] ?? null) || ! preg_match('/\A[A-Za-z0-9._-]+\.apps\.googleusercontent\.com\z/', $config['client_id'])
            || ! is_string($config['client_secret'] ?? null) || trim($config['client_secret']) === '') {
            return false;
        }
        $redirect = $config['redirect'] ?? null;
        if (! is_string($redirect) || ! filter_var($redirect, FILTER_VALIDATE_URL)) { return false; }
        $url = parse_url($redirect);
        $app = parse_url((string) config('app.url'));
        if (! is_array($url) || ! is_array($app) || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])
            || ($url['path'] ?? '') !== '/auth/google/callback'
            || strtolower($url['host'] ?? '') !== strtolower($app['host'] ?? '')
            || ($url['scheme'] ?? '') !== ($app['scheme'] ?? '') || ($url['port'] ?? null) !== ($app['port'] ?? null)) {
            return false;
        }
        return ($url['scheme'] ?? '') === 'https'
            || (app()->environment('local') && ($url['scheme'] ?? '') === 'http' && in_array(strtolower($url['host'] ?? ''), ['localhost', '127.0.0.1', '[::1]'], true));
    }

    public function provider(): GoogleProvider
    {
        if (! $this->configured()) { throw new GoogleLoginException(GoogleLoginException::UNAVAILABLE); }
        // Fresh provider per flow; keep Socialite's session state verification enabled.
        return Socialite::buildProvider(GoogleProvider::class, config('services.google'))
            ->setScopes(['openid', 'email', 'profile'])
            ->setHttpClient(new Client(['timeout' => 15, 'connect_timeout' => 5, 'allow_redirects' => false]));
    }

    public function resolveUser(): User
    {
        // user() verifies session state before exchanging the authorization code.
        // GoogleProvider retrieves Google's HTTPS userinfo using that access token.
        $google = $this->provider()->user();
        $raw = $google->getRaw();
        $subject = $raw['sub'] ?? null;
        $email = $raw['email'] ?? null;
        if (! is_string($subject) || ! preg_match('/\A[A-Za-z0-9._-]{1,191}\z/', $subject)
            || $google->getId() !== $subject || ($raw['email_verified'] ?? null) !== true
            || ! is_string($email) || strlen($email) > 255 || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new GoogleLoginException(GoogleLoginException::FAILED);
        }
        $email = mb_strtolower(trim($email));
        $name = is_string($raw['name'] ?? null) ? trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $raw['name']) ?? '') : '';
        $name = $name === '' ? 'Pengguna Dapur Rantau' : mb_substr($name, 0, 100);
        // The schema requires a password. Nobody receives this random password;
        // existing email-login/reset handlers reject accounts with a social identity.
        $password = Hash::make(Str::random(64));
        unset($google, $raw); // Never persist the Socialite user object or its tokens.

        return DB::transaction(function () use ($subject, $email, $name, $password) {
            // First statement is a write, avoiding read-to-write upgrade on SQLite.
            // A zero-row UPDATE still acquires SQLite's writer reservation.
            SocialAccount::where('provider', 'google')->where('provider_subject', $subject)->update(['updated_at' => now()]);
            $account = SocialAccount::where('provider', 'google')->where('provider_subject', $subject)->first();
            if ($account) {
                $user = $account->user;
                if (! $user || ! $user->email_verified_at) { throw new GoogleLoginException(GoogleLoginException::FAILED); }
                // Subject is authoritative. Do not overwrite the local email/name.
                return $user;
            }
            if (User::whereRaw('lower(email) = ?', [$email])->exists()) {
                throw new GoogleLoginException(GoogleLoginException::EMAIL_USED);
            }
            $user = new User;
            $user->forceFill(['name' => $name, 'email' => $email, 'password' => $password, 'email_verified_at' => now()])->save();
            $account = new SocialAccount;
            $account->forceFill(['user_id' => $user->id, 'provider' => 'google', 'provider_subject' => $subject])->save();
            // Unique email and (provider, provider_subject) remain final race guards.
            return $user;
        });
    }
}
