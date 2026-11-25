<?php

namespace App\Services;

use App\Support\SqliteContention;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;
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
        $client = $this->credential('client_key');

        return $this->serverKeyReady() && ($this->signatureSlots()['current']['state'] ?? null) === 'active'
            && ($this->environment() === 'production'
                ? str_starts_with($client, 'Mid-client-')
                // Both public Client Key formats are issued for Sandbox merchants.
                : Str::startsWith($client, ['SB-Mid-client-', 'Mid-client-']));
    }

    /** The explicitly configured gateway environment; never falls back to another one. */
    public function environment(): ?string
    {
        $environment = config('midtrans.environment');
        if (! in_array($environment, ['sandbox', 'production'], true)) {
            return null;
        }

        // A production application must never grant access from simulated payments.
        return $environment === 'sandbox' && app()->environment('production') ? null : $environment;
    }

    public function webhookEnabled(): bool
    {
        return config('midtrans.webhook_enabled') === true;
    }

    public function serverKeyReady(): bool
    {
        $environment = $this->environment();
        $key = $this->credential('server_key');

        return $environment !== null && str_starts_with($key, $environment === 'production' ? 'Mid-server-' : 'SB-Mid-server-');
    }

    public function clientKey(): string
    {
        return $this->credential('client_key');
    }

    public function snapScriptUrl(): ?string
    {
        $environment = $this->environment();

        return $environment ? config("midtrans.{$environment}.snap_url").'/snap/snap.js' : null;
    }

    private function credential(string $name): string
    {
        $environment = $this->environment();

        return $environment ? $this->configured($environment, $name) : '';
    }

    private function configured(string $environment, string $name): string
    {
        $value = config("midtrans.{$environment}.{$name}");

        return is_string($value) ? trim($value) : '';
    }

    /**
     * Signature Secret Key slots of the active environment (N5 rotation). A slot is "active" only when
     * it can verify notifications: the previous key needs a valid current key and a deadline at most
     * signature_rotation_max_hours ahead. A secret equal to any Server/Client Key or to the other
     * environment's secrets is never used. Secret values are never part of the public state.
     *
     * @return array<string, array{state: string, secret?: string, until?: Carbon}>
     */
    private function signatureSlots(): array
    {
        $environment = $this->environment();
        if ($environment === null) {
            return [];
        }
        $forbidden = [];
        foreach (['sandbox', 'production'] as $other) {
            $names = $other === $environment ? ['server_key', 'client_key'] : ['server_key', 'client_key', 'signature_secret_key', 'signature_secret_key_previous'];
            foreach ($names as $name) {
                $forbidden[] = $this->configured($other, $name);
            }
        }
        $reused = fn (string $secret) => collect($forbidden)->contains(fn (string $value) => $value !== '' && hash_equals($value, $secret));
        $current = $this->configured($environment, 'signature_secret_key');
        $slots['current'] = match (true) {
            $current === '' => ['state' => 'missing'],
            $reused($current) => ['state' => 'reused'],
            default => ['state' => 'active', 'secret' => $current],
        };
        $previous = $this->configured($environment, 'signature_secret_key_previous');
        $until = $this->rotationDeadline($this->configured($environment, 'signature_previous_until'));
        $slots['previous'] = match (true) {
            $previous === '' => ['state' => 'missing'],
            $reused($previous) || ($current !== '' && hash_equals($current, $previous)) => ['state' => 'reused'],
            $until === null => ['state' => 'no_deadline'],
            $until->isPast() => ['state' => 'expired', 'until' => $until],
            $until->gt(now()->addHours((int) config('midtrans.signature_rotation_max_hours', 168))) => ['state' => 'too_long', 'until' => $until],
            $slots['current']['state'] !== 'active' => ['state' => 'no_current', 'until' => $until],
            default => ['state' => 'active', 'secret' => $previous, 'until' => $until],
        };

        return $slots;
    }

    /** ISO-8601 with an explicit offset only, so a deadline is never read in an assumed timezone. */
    private function rotationDeadline(string $value): ?Carbon
    {
        if (! preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2})?(?:Z|[+-]\d{2}:\d{2})\z/', $value)) {
            return null;
        }
        try {
            return Carbon::parse($value)->utc();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Rotation state without secret values, for payments:signature-status.
     *
     * @return array{environment: ?string, current: string, previous: string, until: ?string}
     */
    public function signatureStatus(): array
    {
        $slots = $this->signatureSlots();

        return ['environment' => $this->environment(), 'current' => $slots['current']['state'] ?? 'missing', 'previous' => $slots['previous']['state'] ?? 'missing',
            'until' => isset($slots['previous']['until']) ? $slots['previous']['until']->toIso8601String() : null];
    }

    /** Slot whose key produced the SHA-512 notification signature, or null. Every active key is compared. */
    private function signatureSlot(array $notice, array $slots): ?string
    {
        $matched = null;
        foreach ($slots as $slot => $entry) {
            if ($entry['state'] === 'active') {
                $digest = hash('sha512', $notice['order_id'].$notice['status_code'].$notice['gross_amount'].$entry['secret']);
                $matched = hash_equals($digest, $notice['signature_key']) && $matched === null ? $slot : $matched;
            }
        }

        return $matched;
    }

    private function url(string $host, string $path): string
    {
        $environment = $this->environment();
        abort_if($environment === null, 503, 'Konfigurasi Midtrans belum tersedia.');

        return config("midtrans.{$environment}.{$host}").$path;
    }

    private function serverKey(): string
    {
        abort_unless($this->serverKeyReady(), 503, 'Kunci Midtrans belum tersedia.');

        return $this->credential('server_key');
    }

    private function client(int $connect = 3, int $timeout = 8): PendingRequest
    {
        return Http::withBasicAuth($this->serverKey(), '')->acceptJson()->withoutRedirecting()
            ->connectTimeout($connect)->timeout($timeout);
    }

    public function checkout(int $userId, string $code): string
    {
        abort_unless($this->ready(), 503, 'Checkout menunggu konfigurasi kunci Midtrans.');
        $client = $this->client();
        $plan = DB::table('subscription_plans')->where('code', $code)->firstOrFail();
        $order = (string) Str::uuid();
        DB::table('payments')->insert([
            'order_id' => $order, 'user_id' => $userId, 'plan_id' => $plan->id,
            'amount' => $plan->price, 'created_at' => now(), 'updated_at' => now(),
        ]);
        try {
            $response = $client->post($this->url('snap_url', '/snap/v1/transactions'), [
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
        abort_unless($this->webhookEnabled(), 404);
        $order = $notice['order_id'];
        $slots = $this->signatureSlots();
        if (($slots['current']['state'] ?? null) !== 'active') {
            // No usable Signature Secret Key: refuse without trying Server Key or Client Key.
            Log::error('payment.webhook_signature_unconfigured', ['environment' => $this->environment(), 'current' => $slots['current']['state'] ?? 'missing']);
            abort(503);
        }
        $slot = $this->signatureSlot($notice, $slots);
        abort_if($slot === null, 403);
        if ($slot === 'previous') {
            Log::warning('payment.webhook_previous_signature_key', ['environment' => $this->environment(), 'until' => $slots['previous']['until']->toIso8601String()]);
        }
        if (! Str::isUuid($order)) {
            // Signed by Midtrans but not an order of this application (e.g. Dashboard Test Notification).
            Log::info('payment.webhook_foreign_order', ['environment' => $this->environment(), 'signature_slot' => $slot,
                'transaction_status' => $notice['transaction_status'], 'order_hash' => substr(hash('sha256', $order), 0, 16)]);

            return;
        }
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
            if ($refunded) {
                $this->adjustments->flagRefundExcess(DB::table('payments')->where('id', $payment->id)->first(), $this->adjustments->listedRefunds($current), 'webhook');
            }
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
                    if ($exception->getStatusCode() !== 503) {
                        Log::warning('payment.reconcile_unverified', ['payment_id' => $payment->id, 'status' => $exception->getStatusCode()]);
                    }
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
            return in_array($payment->status, ['paid', 'chargeback'], true) ? null : $this->apply($payment, $this->paymentStatus($current), $current);
        }
        $outcome = $adjustment[0] === 'partial_refund'
            ? $this->adjustments->recordMidtransPartialRefund($payment, $current)
            : $this->adjustments->record($payment, ...$adjustment);
        $this->adjustments->flagRefundExcess(DB::table('payments')->where('id', $payment->id)->first(), $this->adjustments->listedRefunds($current));

        return $outcome === 'unchanged' ? null : $outcome;
    }

    private function adjustment(object $payment, array $current): ?array
    {
        $status = $current['transaction_status'] ?? null;
        $settled = in_array($payment->status, ['paid', 'chargeback'], true);
        $kind = match (true) {
            in_array($status, ['refund', 'chargeback'], true) => $status,
            $settled && in_array($status, ['partial_refund', 'partial_chargeback'], true) => $status,
            $payment->status === 'pending' && $status === 'partial_refund' => $status,
            // A refund Midtrans lists after the payment was fully refunded must still reach the ledger.
            $payment->status === 'refunded' && $status === 'partial_refund' => $status,
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

    /**
     * Apply an already verified Get Status payload through the same adjustment rules as webhooks.
     */
    public function applyGatewayStatus(int $paymentId, array $current): ?string
    {
        return $this->settle(DB::table('payments')->where('id', $paymentId)->first(), $current);
    }

    /**
     * Send one Refund API request. The caller must persist the refund intent first;
     * this method never retries and reports an indeterminate result as "unknown".
     */
    public function requestRefund(object $payment, string $refundKey, int $amount, string $reason): array
    {
        $client = $this->client(3, 15);
        $url = $this->url('api_url', "/v2/{$payment->order_id}/refund");
        try {
            $response = $client->post($url, ['refund_key' => $refundKey, 'amount' => $amount, 'reason' => $reason]);
        } catch (ConnectionException) {
            return ['outcome' => 'unknown', 'http_status' => null, 'status_code' => null];
        }
        $body = $response->json();
        $code = is_array($body) && is_string($body['status_code'] ?? null) && preg_match('/\A[0-9]{3}\z/', $body['status_code']) ? $body['status_code'] : null;
        $outcome = match (true) {
            $response->successful() && $code === '200' && ($body['order_id'] ?? null) === $payment->order_id
                && ($body['refund_key'] ?? null) === $refundKey => 'accepted',
            // Only definitive rejections free the refund slot. 406 (duplicate refund key) means an
            // earlier attempt may already exist; 429 and anything else stays unknown.
            in_array($code, ['400', '401', '402', '403', '404', '410', '411', '412', '413', '414'], true) && ! $response->serverError() => 'rejected',
            default => 'unknown',
        };
        $reference = is_array($body) ? ($body['refund_chargeback_id'] ?? null) : null;

        return ['outcome' => $outcome, 'http_status' => $response->status(), 'status_code' => $code,
            'refund_chargeback_id' => is_scalar($reference) ? mb_substr((string) $reference, 0, 100) : null];
    }

    public function currentStatus(object $payment): ?array
    {
        try {
            $response = $this->client(2, 5)->get($this->url('api_url', "/v2/{$payment->order_id}/status"));
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

    private function apply(object $payment, string $status, array $current): ?string
    {
        if ($status === 'paid' && ! $this->subscriptions->supports((int) $payment->plan_id)) {
            $status = 'review';
        }
        $review = null;
        $changed = DB::transaction(function () use ($payment, $status, $current, &$review) {
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
                $review = $this->adjustments->record($payment, 'settlement', 'midtrans', null, $this->settlementReference($payment, $current));
            }

            return $changed;
        }, 3);
        if ($changed && $status === 'review') {
            DB::afterCommit(fn () => Log::warning('payment.review', ['payment_id' => $payment->id, 'plan_id' => $payment->plan_id]));
        }

        return $changed ? $status : ($review === 'unchanged' ? null : $review);
    }

    private function settlementReference(object $payment, array $current): string
    {
        $transaction = $current['transaction_id'] ?? null;
        if (! is_string($transaction) || trim($transaction) === '' || strlen($transaction) > 100
            || DB::table('payment_adjustments')->where(['payment_id' => $payment->id, 'source' => 'midtrans', 'reference' => 'settlement'])->exists()) {
            return 'settlement';
        }

        return 'settlement:'.hash('sha256', $transaction);
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
