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
 *
 * Mail outcome is recorded honestly: "sent" only when a real transport accepted the message,
 * "logged_only" when the mailer just writes to a log/array, "failed" otherwise.
 */
class PaymentAlert
{
    private const REAL_TRANSPORTS = ['smtp', 'sendmail', 'ses', 'ses-v2', 'postmark', 'resend', 'mailgun'];

    public static function raise(string $event, array $context): void
    {
        DB::afterCommit(function () use ($event, $context) {
            Log::error($event, $context + ['alert' => true]);
            $readiness = static::mailReadiness();
            if ($readiness['status'] === 'disabled') {
                return;
            }
            if ($readiness['status'] !== 'ready') {
                // Never claim an alert email went out when the mailer cannot deliver it.
                $status = $readiness['status'] === 'logged_only' ? 'logged_only' : 'failed';
                Log::warning('payment.alert_mail', ['event' => $event, 'mail_status' => $status, 'mailer' => $readiness['mailer'],
                    'transport' => $readiness['transport'], 'issues' => implode(',', $readiness['issues'])]);

                return;
            }
            try {
                Mail::mailer($readiness['mailer'])->to($readiness['to'])->send(new PaymentAlertMail($event, $context));
                Log::info('payment.alert_mail', ['event' => $event, 'mail_status' => 'sent', 'mailer' => $readiness['mailer'], 'transport' => $readiness['transport']]);
            } catch (Throwable $exception) {
                // An unavailable mailer must never break payment processing; the error log entry stays.
                Log::error('payment.alert_mail', ['event' => $event, 'mail_status' => 'failed', 'mailer' => $readiness['mailer'], 'exception' => $exception::class]);
            }
        });
    }

    /**
     * Whether alert email can really be delivered, without exposing any credential value.
     *
     * Status: disabled | ready | logged_only | incomplete | unsupported.
     *
     * @return array{status: string, to: ?string, mailer: ?string, transport: ?string, issues: list<string>}
     */
    public static function mailReadiness(): array
    {
        $to = config('midtrans.alerts.mail_to');
        if (! is_string($to) || trim($to) === '') {
            return ['status' => 'disabled', 'to' => null, 'mailer' => null, 'transport' => null, 'issues' => []];
        }
        $mailer = (string) config('mail.default');
        $transport = config("mail.mailers.{$mailer}.transport");
        $transport = is_string($transport) ? $transport : null;
        $issues = [];
        if (! filter_var(trim($to), FILTER_VALIDATE_EMAIL)) {
            $issues[] = 'PAYMENT_ALERT_EMAIL tidak valid';
        }
        $from = config('mail.from.address');
        if (! is_string($from) || ! filter_var($from, FILTER_VALIDATE_EMAIL) || str_ends_with(strtolower($from), '@example.com')) {
            $issues[] = 'MAIL_FROM_ADDRESS belum diisi alamat nyata';
        }
        $missing = fn (string $key) => ! filled(config($key));
        $issues = [...$issues, ...match ($transport) {
            'smtp' => array_values(array_filter([$missing("mail.mailers.{$mailer}.host") ? 'MAIL_HOST kosong' : null,
                $missing("mail.mailers.{$mailer}.port") ? 'MAIL_PORT kosong' : null])),
            'ses', 'ses-v2' => $missing('services.ses.key') || $missing('services.ses.secret') ? ['credential SES belum diisi'] : [],
            'postmark' => $missing('services.postmark.key') ? ['credential Postmark belum diisi'] : [],
            'resend' => $missing('services.resend.key') ? ['credential Resend belum diisi'] : [],
            'mailgun' => $missing('services.mailgun.domain') || $missing('services.mailgun.secret') ? ['credential Mailgun belum diisi'] : [],
            default => [],
        }];
        $status = match (true) {
            in_array($transport, ['log', 'array'], true) => 'logged_only',
            ! in_array($transport, self::REAL_TRANSPORTS, true) => 'unsupported',
            $issues !== [] => 'incomplete',
            default => 'ready',
        };
        if ($status === 'unsupported') {
            // failover/roundrobin may silently end in a log transport, like AuthMailService refuses.
            $issues[] = "transport {$transport} tidak didukung untuk alert";
        }

        return ['status' => $status, 'to' => trim($to), 'mailer' => $mailer, 'transport' => $transport, 'issues' => $issues];
    }
}
