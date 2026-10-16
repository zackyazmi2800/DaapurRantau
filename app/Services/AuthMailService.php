<?php

namespace App\Services;

use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class AuthMailService
{
    public function mailer(): string
    {
        $mailer = (string) config('mail.default');
        // Fail closed: failover/roundrobin may silently send secrets to a log transport.
        if (! in_array(config('mail.mailers.'.$mailer.'.transport'), ['smtp', 'sendmail', 'ses', 'ses-v2', 'postmark', 'resend', 'mailgun'], true)) {
            throw ValidationException::withMessages(['email' => 'Pengiriman email akun belum tersedia. Silakan coba kembali nanti.']);
        }

        return $mailer;
    }

    public function otp(string $email, string $otp): void
    {
        Mail::mailer($this->mailer())->to($email)->send(new \App\Mail\VerificationOtp($otp));
    }

    public function reset(string $email, string $token): void
    {
        // Use the configured application origin, never an incoming Host header.
        $baseUrl = rtrim((string) config('app.url'), '/');
        $parts = parse_url($baseUrl);
        if (! is_array($parts) || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])) {
            throw ValidationException::withMessages(['email' => 'Pengiriman email akun belum tersedia. Silakan coba kembali nanti.']);
        }
        $url = $baseUrl.route('password.reset', ['token' => $token, 'email' => $email], false);
        Mail::mailer($this->mailer())->to($email)->send(new \App\Mail\PasswordResetLink($url));
    }
}
