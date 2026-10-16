<?php
namespace App\Mail;

use Illuminate\Mail\Mailable;

class PasswordResetLink extends Mailable
{
    public function __construct(private string $url) {}

    public function build(): static
    {
        return $this->subject('Reset password Dapur Rantau')->view('mail.reset', [
            'url' => $this->url,
            'expiresInMinutes' => config('auth.passwords.'.config('auth.defaults.passwords').'.expire'),
        ]);
    }
}
