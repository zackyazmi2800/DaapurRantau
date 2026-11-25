<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class PaymentAlertMail extends Mailable
{
    public function __construct(public string $event, public array $context) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: '[Dapur Rantau] Peringatan pembayaran: '.$this->event);
    }

    public function content(): Content
    {
        $lines = collect($this->context)->map(fn ($value, $key) => e($key).': '.e(is_scalar($value) ? (string) $value : json_encode($value)))->implode('<br>');

        return new Content(htmlString: '<p>Peristiwa pembayaran yang perlu ditinjau operator: <strong>'.e($this->event).'</strong></p><p>'.$lines
            .'</p><p>Periksa dengan <code>php artisan payments:gateway-refunds</code> dan runbook docs/operasional-pembayaran.md.</p>');
    }
}
