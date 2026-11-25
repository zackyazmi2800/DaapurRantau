<?php

namespace App\Support;

use App\Mail\PaymentAlertMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Payment anomalies that need a human. Always an error-level log entry; additionally an email
 * through the application's mailer when PAYMENT_ALERT_EMAIL is configured. Context carries
 * identifiers only (no credentials, refund reasons or customer data).
 */
class PaymentAlert
{
    public static function raise(string $event, array $context): void
    {
        DB::afterCommit(function () use ($event, $context) {
            Log::error($event, $context + ['alert' => true]);
            $to = config('midtrans.alerts.mail_to');
            if (! is_string($to) || ! filter_var($to, FILTER_VALIDATE_EMAIL)) {
                return;
            }
            try {
                Mail::to($to)->send(new PaymentAlertMail($event, $context));
            } catch (Throwable $exception) {
                // An unavailable mailer must never break payment processing; the log entry stays.
                Log::error('payment.alert_mail_failed', ['event' => $event, 'exception' => $exception::class]);
            }
        });
    }
}
