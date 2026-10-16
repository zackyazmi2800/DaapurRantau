<?php
namespace App\Mail;

use Illuminate\Mail\Mailable;

class VerificationOtp extends Mailable
{
    public function __construct(private string $otp) {}

    public function build(): static
    {
        return $this->subject('Kode verifikasi Dapur Rantau')->view('mail.otp', ['otp' => $this->otp]);
    }
}
