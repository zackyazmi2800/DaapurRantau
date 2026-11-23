<?php

namespace App\Services;

use App\Support\SqliteContention;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PDOException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class MidtransSandboxService
{
    public function __construct(private SubscriptionService $subscriptions, private PaymentAdjustmentService $adjustments) {}

    public function ready(): bool
    {
        return $this->sandboxEnabled()
            && filled(config('midtrans.signature_secret_key'))
            && str_starts_with((string) config('midtrans.server_key'), 'SB-Mid-server-')
            // Both public Client Key formats are issued for Sandbox merchants.
            && Str::startsWith((string) config('midtrans.client_key'), ['SB-Mid-client-', 'Mid-client-']);
    }

    private function sandboxEnabled(): bool
    {
        return app()->environment('local', 'testing') && config('midtrans.is_production') === false;
    }

    private function serverKey(): string
    {
        abort_unless($this->sandboxEnabled(), 503);
        $key = (string) config('midtrans.server_key');
        abort_unless(str_starts_with($key, 'SB-Mid-server-'), 503, 'Kunci Sandbox belum tersedia.');

        return $key;
    }

    private function client(int $connect = 3, int $timeout = 8): PendingRequest
    {
        return Http::withBasicAuth($this->serverKey(), '')->acceptJson()->withoutRedirecting()
            ->connectTimeout($connect)->timeout($timeout);
    }

    public function checkout(int $userId, string $code): string
    {
        abort_unless($this->ready(), 503, 'Checkout menunggu konfigurasi kunci Sandbox.');
        $client = $this->client();
        $plan = DB::table('subscription_plans')->where('code', $code)->firstOrFail();
        $order = (string) Str::uuid();
        DB::table('payments')->insert([
            'order_id' => $order, 'user_id' => $userId, 'plan_id' => $plan->id,
            'amount' => $plan->price, 'created_at' => now(), 'updated_at' => now(),
        ]);
        try {
            $response = $client->post('https://app.sandbox.midtrans.com/snap/v1/transactions', [
                'transaction_details' => ['order_id' => $order, 'gross_amount' => $plan->price],
                'credit_card' => ['secure' => true],
            ]);
            $token = $response->successful() ? $response->json('token') : null;
        } catch (ConnectionException) {
            $token = null;
        }
        if (! is_string($token) || $token === '') {
            DB::table('payments')->where('order_id', $order)->where('status', 'pending')
                ->update(['status' => 'failed', 'updated_at' => now()]);
            abort(503, 'Checkout belum tersedia. Silakan coba kembali.');
        }
        DB::table('payments')->where('order_id', $order)->update(['snap_token' => $token]);

        return $order;
    }

    public function notify(array $notice): void
    {
        $order = $notice['order_id'];
        $secret = config('midtrans.signature_secret_key');
        abort_unless($this->sandboxEnabled() && is_string($secret) && filled($secret), 503);
        $digest = hash('sha512', $order.$notice['status_code'].$notice['gross_amount'].$secret);
        abort_unless(hash_equals($digest, $notice['signature_key']), 403);
        $payment = DB::table('payments')->where('order_id', $order)->first();
        abort_unless($payment, 404);
        abort_unless($this->matchesAmount($notice['gross_amount'], $payment->amount), 422);
        $settled = in_array($payment->status, ['paid', 'chargeback'], true);
        $reversing = in_array($notice['transaction_status'], ['refund', 'partial_refund', 'chargeback', 'partial_chargeback', 'deny', 'cancel', 'expire', 'failure'], true);
        $refunded = $payment->status === 'refunded';
        if (($refunded && $notice['transaction_status'] !== 'partial_refund') || ($settled && ! $reversing)) {
            return;
        }

        // Status and fraud flags are outside the notification signature.
        $current = $this->currentStatus($payment);
        abort_if($current === null, 503, 'Status pembayaran belum dapat diverifikasi.');
        if ($refunded && ($current['transaction_status'] ?? null) !== 'partial_refund') {
            return;
        }

        // A delayed notification may differ from the current gateway state.
        if (! $refunded && ! $settled && $this->adjustment($payment, $current) === null && $this->paymentStatus($current) === 'paid' && $this->paymentStatus($notice) !== 'paid') {
            // A stale or challenged notice cannot grant access.
            return;
        }

        try {
            $refunded ? $this->adjustments->recordMidtransPartialRefund($payment, $current) : $this->settle($payment, $current);
        } catch (Throwable $exception) {
            if ($exception instanceof HttpException || SqliteContention::isBusy($exception)) {
                throw $exception;
            }
            $this->logFailure('webhook', $payment, $exception);

            throw new HttpException(500, 'Server Error');
        }
    }

    public function sync(int $userId, string $order): string|false|null
    {
        $payment = DB::table('payments')->where(['order_id' => $order, 'user_id' => $userId])->first();
        abort_unless($payment, 404);
        try {
            return $this->refresh($payment);
        } catch (HttpException) {
            return false;
        } catch (Throwable $exception) {
            if (SqliteContention::isBusy($exception)) {
                return 'busy';
            }
            $this->logFailure('sync', $payment, $exception);

            return 'error';
        }
    }

    public function reconcile(?string $order = null, int $limit = 20): array
    {
        if (! $this->ready()) {
            return ['inactive' => 1];
        }
        $lock = Cache::lock('payments:reconcile', 120);
        try {
            if (! $lock->get()) {
                return ['skipped' => 1];
            }
        } catch (Throwable $exception) {
            return SqliteContention::isBusy($exception) ? ['busy' => 1] : throw $exception;
        }
        try {
            return $this->reconcileBatch($order, $limit);
        } finally {
            $lock->release();
        }
    }

    private function reconcileBatch(?string $order, int $limit): array
    {
        $query = DB::table('payments')->whereIn('status', ['pending', 'review']);
        if ($order !== null) {
            $query->where('order_id', $order);
        } else {
            $query->where('created_at', '<=', now()->subMinutes(10))
                ->where(fn ($query) => $query->where('status', 'review')->orWhere('created_at', '>=', now()->subDays(7)))
                ->where(fn ($query) => $query->where('updated_at', '<=', now()->subHour())
                    ->orWhere(fn ($query) => $query->where('created_at', '>', now()->subHours(2))->where('updated_at', '<=', now()->subMinutes(10))));
        }
        $counts = [];
        $failures = 0;
        $started = microtime(true);
        foreach ($query->orderBy('updated_at')->orderBy('id')->limit($limit)->get() as $payment) {
            if ($failures >= 3 || microtime(true) - $started > 40) {
                break;
            }
            try {
                try {
                    $outcome = $this->reconcilePayment($payment);
                    $failures = 0;
                } catch (HttpException $exception) {
                    $outcome = 'unverified';
                    $failures += $exception->getStatusCode() === 503 ? 1 : 0;
                    $this->touch($payment);
                }
            } catch (Throwable $exception) {
                if (SqliteContention::isBusy($exception)) {
                    $counts['busy'] = 1;
                    break;
                }
                $this->logFailure('reconcile', $payment, $exception);
                rescue(fn () => $this->touch($payment), report: false);
                $outcome = 'error';
                $failures++;
            }
            $counts[$outcome] = ($counts[$outcome] ?? 0) + 1;
        }

        return $counts;
    }

    private function reconcilePayment(object $payment): string
    {
        if ($payment->status === 'review' && ! $this->subscriptions->supports((int) $payment->plan_id)) {
            $this->touch($payment);

            return 'unchanged';
        }
        $status = $this->refresh($payment, $applied);
        if ($status === null && $payment->status === 'pending' && $payment->created_at <= now()->subHours(48)->toDateTimeString()) {
            return DB::table('payments')->where('id', $payment->id)->where('status', 'pending')
                ->update(['status' => 'expired', 'updated_at' => now()]) ? 'expired' : 'unchanged';
        }
        if ($status === null || $status === $payment->status) {
            $this->touch($payment);
        }

        return match (true) {
            $status === null => 'unrecorded',
            $applied !== null && $applied !== $payment->status => $applied,
            default => 'unchanged',
        };
    }

    private function touch(object $payment): void
    {
        DB::table('payments')->where('id', $payment->id)->where('status', $payment->status)->update(['updated_at' => now()]);
    }

    private function logFailure(string $operation, object $payment, Throwable $exception): void
    {
        $name = fn (string $class): string => strstr($class, "\0", true) ?: $class;
        $frames = $exception->getTrace();
        foreach ($frames as $index => $frame) {
            if (str_starts_with($frame['class'] ?? '', 'App\\')) {
                $origin = $name($frame['class']).'::'.$frame['function'].':'.($index ? $frames[$index - 1]['line'] ?? 0 : $exception->getLine());
                break;
            }
        }
        $database = $exception instanceof PDOException;
        Log::error('payment.processing_failed', [
            'payment_id' => $payment->id, 'operation' => $operation, 'category' => $database ? 'database' : 'unexpected',
            'exception' => $name($exception::class), 'origin' => $origin ?? null,
        ] + ($database ? ['sqlstate' => $exception->errorInfo[0] ?? null, 'driver_code' => $exception->errorInfo[1] ?? null] : []));
    }

    private function refresh(object $payment, ?string &$applied = null): ?string
    {
        $applied = null;
        if ($payment->status === 'paid') {
            return 'paid';
        }
        $current = $this->currentStatus($payment);
        if ($current === null) {
            return null;
        }
        $applied = $this->settle($payment, $current);

        return DB::table('payments')->where('id', $payment->id)->value('status');
    }

    private function settle(object $payment, array $current): ?string
    {
        $adjustment = $this->adjustment($payment, $current);
        if ($adjustment === null) {
            return in_array($payment->status, ['paid', 'chargeback'], true) ? null : $this->apply($payment, $this->paymentStatus($current));
        }
        $outcome = $adjustment[0] === 'partial_refund'
            ? $this->adjustments->recordMidtransPartialRefund($payment, $current)
            : $this->adjustments->record($payment, ...$adjustment);

        return $outcome === 'unchanged' ? null : $outcome;
    }

    private function adjustment(object $payment, array $current): ?array
    {
        $status = $current['transaction_status'] ?? null;
        $settled = in_array($payment->status, ['paid', 'chargeback'], true);
        $kind = match (true) {
            in_array($status, ['refund', 'chargeback'], true) => $status,
            $settled && in_array($status, ['partial_refund', 'partial_chargeback'], true) => $status,
            $settled && in_array($status, ['deny', 'cancel', 'expire', 'failure'], true) => 'reversal',
            default => null,
        };
        if ($kind === null) {
            return null;
        }
        $amount = $kind === 'refund' ? (int) $payment->amount : null;
        $reason = null;

        return [$kind, 'midtrans', $amount, match ($kind) {
            'partial_refund' => 'partial_refund:cumulative',
            'reversal' => "reversal:{$status}",
            default => $kind,
        }, $reason];
    }

    private function currentStatus(object $payment): ?array
    {
        try {
            $response = $this->client(2, 5)->get("https://api.sandbox.midtrans.com/v2/{$payment->order_id}/status");
        } catch (ConnectionException) {
            abort(503, 'Status pembayaran belum dapat diverifikasi.');
        }
        $current = $response->json();
        // Snap orders exist at Midtrans only after a payment method is chosen.
        if ($response->status() === 404 || (is_array($current) && ($current['status_code'] ?? null) === '404')) {
            return null;
        }
        abort_unless($response->successful(), 503, 'Status pembayaran belum dapat diverifikasi.');
        abort_unless(is_array($current) && ($current['order_id'] ?? null) === $payment->order_id
            && $this->matchesAmount($current['gross_amount'] ?? null, $payment->amount)
            && ($current['currency'] ?? 'IDR') === 'IDR', 422);

        return $current;
    }

    private function apply(object $payment, string $status): ?string
    {
        if ($status === 'paid' && ! $this->subscriptions->supports((int) $payment->plan_id)) {
            $status = 'review';
        }
        $review = null;
        $changed = DB::transaction(function () use ($payment, $status, &$review) {
            $review = null;
            $query = DB::table('payments')->where('id', $payment->id)->whereNotIn('status', ['paid', 'refunded', 'chargeback']);
            if ($status === 'paid') {
                $query->whereNotExists(fn ($query) => $query->from('subscription_periods')->whereColumn('subscription_periods.payment_id', 'payments.id'))
                    ->whereNotExists(fn ($query) => $query->from('payment_adjustments')->whereColumn('payment_adjustments.payment_id', 'payments.id'));
            }
            if ($status === 'pending') {
                $query->where('status', 'pending');
            }
            if ($status === 'review') {
                $query->where('status', '!=', 'review');
            }
            $changed = $query->update(['status' => $status, 'updated_at' => now()]);
            if ($changed && $status === 'paid') {
                $this->subscriptions->extend($payment);
            }
            if (! $changed && $status === 'paid'
                && ! in_array(DB::table('payments')->where('id', $payment->id)->value('status'), ['paid', 'refunded', 'chargeback'], true)) {
                $review = $this->adjustments->record($payment, 'settlement', 'midtrans', null, 'settlement');
            }

            return $changed;
        }, 3);
        if ($changed && $status === 'review') {
            DB::afterCommit(fn () => Log::warning('payment.review', ['payment_id' => $payment->id, 'plan_id' => $payment->plan_id]));
        }

        return $changed ? $status : ($review === 'unchanged' ? null : $review);
    }

    private function paymentStatus(array $data): string
    {
        $transaction = $data['transaction_status'] ?? null;
        if (in_array($transaction, ['capture', 'settlement'], true)) {
            // Some non-card settlements omit the optional fraud status.
            $fraud = array_key_exists('fraud_status', $data)
                ? $data['fraud_status']
                : ($transaction === 'settlement' ? 'accept' : null);

            return match ($fraud) {
                'accept' => ($data['status_code'] ?? null) === '200' ? 'paid' : abort(422),
                'challenge' => 'pending',
                'deny' => 'failed',
                default => abort(422),
            };
        }

        return match ($transaction) {
            'pending', 'authorize' => 'pending',
            'expire' => 'expired',
            'cancel', 'deny', 'failure' => 'failed',
            default => abort(422),
        };
    }

    private function matchesAmount(mixed $value, int $amount): bool
    {
        return is_string($value) && in_array($value, [(string) $amount, $amount.'.00'], true);
    }
}
