<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

class MidtransSandboxService
{
    public function __construct(private SubscriptionService $subscriptions) {}

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

    private function client(): PendingRequest
    {
        return Http::withBasicAuth($this->serverKey(), '')->acceptJson()->withoutRedirecting()
            ->connectTimeout(3)->timeout(8);
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

        // Status and fraud flags are outside the notification signature.
        $current = $this->currentStatus($payment);
        abort_if($current === null, 503, 'Status pembayaran belum dapat diverifikasi.');

        // A delayed notification may differ from the current gateway state.
        $status = $this->paymentStatus($current);
        if ($status === 'paid' && $this->paymentStatus($notice) !== 'paid') {
            // A stale or challenged notice cannot grant access.
            return;
        }

        $this->apply($payment, $status);
    }

    public function sync(int $userId, string $order): string|false|null
    {
        $payment = DB::table('payments')->where(['order_id' => $order, 'user_id' => $userId])->first();
        abort_unless($payment, 404);
        try {
            $current = $this->currentStatus($payment);
            if ($current === null) {
                return null;
            }
            $this->apply($payment, $this->paymentStatus($current));
        } catch (HttpException) {
            return false;
        }

        return DB::table('payments')->where('id', $payment->id)->value('status');
    }

    private function currentStatus(object $payment): ?array
    {
        try {
            $response = $this->client()->get("https://api.sandbox.midtrans.com/v2/{$payment->order_id}/status");
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

    private function apply(object $payment, string $status): void
    {
        DB::transaction(function () use ($payment, $status) {
            $query = DB::table('payments')->where('id', $payment->id)->where('status', '!=', 'paid');
            if ($status === 'pending') {
                $query->where('status', 'pending');
            }
            $changed = $query->update(['status' => $status, 'updated_at' => now()]);
            if ($changed && $status === 'paid') {
                $this->subscriptions->extend($payment);
            }
        }, 3);
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
