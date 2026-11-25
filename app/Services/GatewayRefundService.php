<?php

namespace App\Services;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * Two-phase Midtrans refunds: a durable intent is stored before the Refund API call,
 * and the result is only trusted after matching refunds[] in Get Status by refund_key.
 */
class GatewayRefundService
{
    /** Requests whose outcome is not settled yet; they hold the payment's refund slot. */
    private const UNRESOLVED = ['pending', 'submitted', 'unknown', 'review'];

    public function __construct(private MidtransSandboxService $gateway, private PaymentAdjustmentService $adjustments) {}

    public function refund(int $paymentId, mixed $amount, mixed $reason, mixed $key, string $operator, bool $confirm, mixed $production): array
    {
        if (! is_string($amount) || ! preg_match('/\A[1-9][0-9]{0,8}\z/', $amount)) {
            return [false, 'Opsi --amount wajib berupa bilangan bulat rupiah yang lebih dari nol.'];
        }
        if (! is_string($reason) || trim($reason) === '' || mb_strlen($reason) > 255) {
            return [false, 'Opsi --reason wajib diisi (maksimal 255 karakter).'];
        }
        if ($key !== null && (! is_string($key) || ! preg_match('/\A[A-Za-z0-9_-]{8,50}\z/', $key))) {
            return [false, 'Opsi --refund-key harus 8-50 karakter berupa huruf, angka, tanda - atau _.'];
        }
        if ($confirm && $key === null) {
            return [false, 'Eksekusi memerlukan --refund-key yang unik. Tidak ada refund dikirim.'];
        }
        $payment = DB::table('payments')->where('id', $paymentId)->first();
        if (! $payment) {
            return [false, 'Pembayaran tidak ditemukan.'];
        }
        $environment = $this->gateway->environment();
        if ($environment === null || ! $this->gateway->serverKeyReady()) {
            return [false, 'Konfigurasi Midtrans (MIDTRANS_ENVIRONMENT dan Server Key) belum lengkap. Tidak ada refund dikirim.'];
        }
        if ($confirm && $environment === 'production' && $production !== $payment->order_id) {
            return [false, 'Refund Production memerlukan --confirm-production=<order_id pembayaran>. Tidak ada refund dikirim.'];
        }
        $amount = (int) $amount;
        if ($blocked = $this->localBlock($payment, $key)) {
            return [false, $blocked];
        }
        try {
            $current = $this->gateway->currentStatus($payment);
        } catch (HttpException) {
            return [false, 'Status transaksi Midtrans belum dapat diverifikasi. Tidak ada refund dikirim.'];
        }
        if ($current === null) {
            return [false, 'Transaksi tidak ditemukan di Midtrans. Tidak ada refund dikirim.'];
        }
        [$error, $plan] = $this->inspect($payment, $current, $amount, $key);
        if ($error !== null) {
            return [false, $error];
        }
        $summary = sprintf('Environment %s, metode %s, pembayaran %s, sudah direfund Midtrans %s, refund manual %s, sisa %s, refund ini %s (%s).',
            $environment, $plan['payment_type'], $this->rupiah((int) $payment->amount), $this->rupiah($plan['gateway']),
            $this->rupiah($plan['manual']), $this->rupiah($plan['remaining']), $this->rupiah($amount),
            $plan['full'] ? 'refund penuh: Premium dari pembayaran ini dicabut' : 'refund sebagian: Premium tidak diubah, dicatat untuk ditinjau');
        if (! $confirm) {
            $hint = $key ?? 'dr-refund-'.str_replace('-', '', (string) Str::uuid());
            $extra = $environment === 'production' ? " --confirm-production={$payment->order_id}" : '';

            return [true, "DRY-RUN, belum ada refund dikirim. {$summary} Untuk mengirim: --confirm --refund-key={$hint}{$extra}"];
        }

        return $this->execute($payment, $environment, $key, $amount, trim($reason), $operator, $plan['gateway']);
    }

    public function reconcile(int $paymentId): array
    {
        $payment = DB::table('payments')->where('id', $paymentId)->first();
        if (! $payment) {
            return [false, 'Pembayaran tidak ditemukan.'];
        }
        $intents = DB::table('payment_gateway_refunds')->where('payment_id', $paymentId)
            ->whereIn('status', [...self::UNRESOLVED, 'succeeded'])->orderBy('id')->get();
        if ($intents->isEmpty()) {
            return [true, 'Tidak ada refund Midtrans yang perlu direkonsiliasi untuk pembayaran ini.'];
        }
        $environment = $this->gateway->environment();
        if ($environment === null || ! $this->gateway->serverKeyReady()) {
            return [false, 'Konfigurasi Midtrans belum lengkap. Tidak ada perubahan.'];
        }
        try {
            $current = $this->gateway->currentStatus($payment);
        } catch (HttpException) {
            return [false, 'Status transaksi Midtrans belum dapat diverifikasi. Tidak ada perubahan.'];
        }
        $lines = [];
        foreach ($intents as $intent) {
            if ($intent->environment !== $environment) {
                $lines[] = "{$intent->refund_key}: dilewati, dibuat di environment {$intent->environment}.";

                continue;
            }
            $after = $current === null ? $this->touch($intent) : $this->verify($payment, $intent, $current);
            $lines[] = "{$intent->refund_key}: {$intent->status} -> {$after}.";
        }

        return [true, 'Rekonsiliasi refund: '.implode(' ', $lines)];
    }

    private function execute(object $payment, string $environment, string $key, int $amount, string $reason, string $operator, int $gateway): array
    {
        try {
            [$id, $error] = DB::transaction(function () use ($payment, $environment, $key, $amount, $reason, $operator, $gateway) {
                DB::table('users')->where('id', $payment->user_id)->update(['id' => DB::raw('id')]);
                $fresh = DB::table('payments')->where('id', $payment->id)->lockForUpdate()->first();
                if ($blocked = $this->localBlock($fresh, $key)) {
                    return [null, $blocked];
                }
                $totals = $this->adjustments->refundTotal($payment->id);
                $remaining = (int) $fresh->amount - max($gateway, $totals['midtrans'], $this->adjustments->gatewayRefunded($payment->id)) - $totals['manual'];
                if ($amount > $remaining) {
                    return [null, 'Nominal melebihi sisa yang dapat direfund ('.$this->rupiah(max(0, $remaining)).'). Tidak ada refund dikirim.'];
                }
                $now = now();

                return [DB::table('payment_gateway_refunds')->insertGetId([
                    'payment_id' => $payment->id, 'refund_key' => $key, 'amount' => $amount, 'reason' => $reason, 'status' => 'pending',
                    'operator' => $operator, 'environment' => $environment, 'active_slot' => $payment->id, 'created_at' => $now, 'updated_at' => $now,
                ]), null];
            }, 3);
        } catch (UniqueConstraintViolationException) {
            return [false, 'Refund lain untuk pembayaran ini sedang diproses atau refund_key sudah dipakai. Tidak ada refund dikirim.'];
        }
        if ($id === null) {
            return [false, $error];
        }
        $intent = DB::table('payment_gateway_refunds')->where('id', $id)->first();
        $this->audit($intent, 'intent');
        try {
            $result = $this->gateway->requestRefund($payment, $key, $amount, $reason);
        } catch (Throwable $exception) {
            // Whatever happened, the request may have reached Midtrans: never resend automatically.
            $result = ['outcome' => 'unknown', 'http_status' => null, 'status_code' => null];
            Log::warning('payment.gateway_refund_request_failed', ['payment_id' => $payment->id, 'refund_id' => $id, 'exception' => $exception::class]);
        }
        DB::table('payment_gateway_refunds')->where('id', $id)->update([
            'status' => $result['outcome'] === 'accepted' ? 'submitted' : ($result['outcome'] === 'rejected' ? 'pending' : 'unknown'),
            'http_status' => $result['http_status'], 'provider_status_code' => $result['status_code'],
            'midtrans_refund_chargeback_id' => $result['refund_chargeback_id'] ?? null, 'submitted_at' => now(), 'updated_at' => now(),
        ]);
        $intent = DB::table('payment_gateway_refunds')->where('id', $id)->first();
        $this->audit($intent, 'request_'.$result['outcome'], ['http_status' => $result['http_status'], 'provider_status_code' => $result['status_code']]);
        try {
            $current = $this->gateway->currentStatus($payment);
        } catch (HttpException) {
            $current = null;
        }
        $status = $current === null ? $this->touch($intent, $result['outcome'] === 'rejected' ? 'unknown' : null)
            : $this->verify($payment, $intent, $current, $result['outcome'] === 'rejected');

        return match ($status) {
            'succeeded' => [true, 'Refund diterima Midtrans dan tercatat di refunds[]. Dana belum tentu diterima pelanggan; menunggu bank_confirmed_at. Jalankan --reconcile untuk memperbarui.'],
            'confirmed' => [true, 'Refund tercatat di Midtrans dan sudah dikonfirmasi bank (bank_confirmed_at).'],
            'rejected' => [false, "Refund ditolak Midtrans (kode {$result['status_code']}). refund_key ini tidak dipakai lagi."],
            'review' => [false, 'Nominal refund di Midtrans berbeda dengan permintaan. Refund ditahan untuk ditinjau; jangan kirim ulang.'],
            default => [false, "Hasil refund belum dapat dipastikan (status {$status}). Jangan kirim ulang; jalankan --reconcile untuk refund_key {$key}."],
        };
    }

    private function localBlock(object $payment, ?string $key): ?string
    {
        if ($payment->status !== 'paid') {
            return 'Hanya pembayaran berstatus paid yang dapat direfund melalui Midtrans. Tidak ada refund dikirim.';
        }
        if ($key !== null) {
            $existing = DB::table('payment_gateway_refunds')->where('refund_key', $key)->first();
            if ($existing) {
                return "refund_key ini sudah tercatat (status {$existing->status}). Jangan kirim ulang; gunakan --reconcile.";
            }
            if (DB::table('payment_adjustments')->where('payment_id', $payment->id)->where(fn ($query) => $query
                ->where(['source' => 'manual', 'reference' => $key])
                ->orWhere(fn ($query) => $query->where(['source' => 'midtrans', 'reference' => 'partial_refund:key:'.hash('sha256', $key)])))->exists()) {
                return 'refund_key ini sudah dipakai sebagai referensi refund lain. Gunakan refund_key baru.';
            }
        }
        $open = DB::table('payment_gateway_refunds')->where('payment_id', $payment->id)->whereIn('status', self::UNRESOLVED)->first();

        return $open ? "Masih ada refund Midtrans yang belum selesai (refund_key {$open->refund_key}, status {$open->status}). Jalankan --reconcile; jangan membuat refund_key baru." : null;
    }

    private function inspect(object $payment, array $current, int $amount, ?string $key): array
    {
        $status = $current['transaction_status'] ?? null;
        if (! in_array($status, ['settlement', 'partial_refund'], true)) {
            return ['Status transaksi Midtrans ('.(is_string($status) ? $status : 'tidak dikenal').') belum settlement. Tidak ada refund dikirim.', null];
        }
        if (($current['fraud_status'] ?? 'accept') !== 'accept') {
            return ['Status fraud transaksi bukan accept. Tidak ada refund dikirim.', null];
        }
        $type = $current['payment_type'] ?? null;
        if (! is_string($type) || ! in_array($type, (array) config('midtrans.refund.payment_types'), true)) {
            return ['Metode pembayaran '.(is_string($type) ? $type : 'tidak dikenal').' tidak didukung Refund API. Gunakan refund manual (payments:refund).', null];
        }
        $refunds = $current['refunds'] ?? [];
        if (! is_array($refunds) || ! array_is_list($refunds)) {
            return ['Rincian refunds[] dari Midtrans tidak valid. Tinjau manual; tidak ada refund dikirim.', null];
        }
        $sum = 0;
        foreach ($refunds as $refund) {
            $value = is_array($refund) ? $this->rupiahValue($refund['refund_amount'] ?? null) : null;
            if ($value === null || ! is_string($refund['refund_key'] ?? null)) {
                return ['Rincian refunds[] dari Midtrans tidak lengkap. Tinjau manual; tidak ada refund dikirim.', null];
            }
            if ($key !== null && $refund['refund_key'] === $key) {
                return ['refund_key ini sudah ada di Midtrans. Gunakan --reconcile atau refund_key baru.', null];
            }
            $sum += $value;
        }
        $cumulative = array_key_exists('refund_amount', $current) ? $this->rupiahValue($current['refund_amount']) : 0;
        if ($cumulative !== $sum) {
            return ['refund_amount kumulatif Midtrans tidak sama dengan jumlah refunds[]. Tinjau manual; tidak ada refund dikirim.', null];
        }
        $totals = $this->adjustments->refundTotal($payment->id);
        $gateway = max($sum, $totals['midtrans'], $this->adjustments->gatewayRefunded($payment->id));
        $remaining = (int) $payment->amount - $gateway - $totals['manual'];
        if ($amount > $remaining) {
            return ['Nominal melebihi sisa yang dapat direfund ('.$this->rupiah(max(0, $remaining)).'). Tidak ada refund dikirim.', null];
        }
        if (in_array($type, (array) config('midtrans.refund.full_only'), true) && ($gateway > 0 || $totals['manual'] > 0 || $amount !== (int) $payment->amount)) {
            return ["Metode {$type} hanya mendukung refund penuh tanpa refund sebelumnya.", null];
        }

        return [null, ['payment_type' => $type, 'gateway' => $gateway, 'manual' => $totals['manual'], 'remaining' => $remaining, 'full' => $amount === $remaining]];
    }

    private function verify(object $payment, object $intent, array $current, bool $rejected = false): string
    {
        $entry = $this->entry($current, $intent->refund_key);
        if ($entry === null) {
            return $this->touch($intent, $rejected ? 'rejected' : null);
        }
        $amount = $this->rupiahValue($entry['refund_amount'] ?? null);
        $confirmed = is_string($entry['bank_confirmed_at'] ?? null) && trim($entry['bank_confirmed_at']) !== '';
        $status = $amount !== (int) $intent->amount ? 'review' : ($confirmed ? 'confirmed' : 'succeeded');
        $text = fn (string $field, int $limit) => is_scalar($entry[$field] ?? null) ? mb_substr((string) $entry[$field], 0, $limit) : null;
        DB::table('payment_gateway_refunds')->where('id', $intent->id)->update([
            'status' => $status, 'active_slot' => $status === 'review' ? $intent->payment_id : null,
            'midtrans_refund_chargeback_id' => $text('refund_chargeback_id', 100) ?? $intent->midtrans_refund_chargeback_id,
            'midtrans_refund_chargeback_uuid' => $text('refund_chargeback_uuid', 100), 'refund_method' => $text('refund_method', 30),
            'gateway_created_at' => $text('created_at', 32), 'bank_confirmed_at' => $confirmed ? $text('bank_confirmed_at', 32) : null,
            'verified_at' => $intent->verified_at ?? now(), 'last_checked_at' => now(), 'updated_at' => now(),
        ]);
        if ($status !== $intent->status) {
            $this->audit($intent, $status, ['refund_method' => $text('refund_method', 30)]);
        }
        try {
            // Same A3/A5/A6/R1/R2 rules as the webhook and payments:reconcile.
            $this->gateway->applyGatewayStatus($payment->id, $current);
        } catch (HttpException $exception) {
            Log::warning('payment.gateway_refund_adjustment_unverified', ['payment_id' => $payment->id, 'refund_id' => $intent->id, 'status' => $exception->getStatusCode()]);
        }

        return $status;
    }

    private function touch(object $intent, ?string $status = null): string
    {
        $status ??= $intent->status;
        DB::table('payment_gateway_refunds')->where('id', $intent->id)->update(['status' => $status, 'last_checked_at' => now(), 'updated_at' => now()]
            + ($status === 'rejected' ? ['active_slot' => null] : []));
        if ($status !== $intent->status) {
            $this->audit($intent, $status);
        }

        return $status;
    }

    private function entry(array $current, string $key): ?array
    {
        $refunds = $current['refunds'] ?? [];
        foreach (is_array($refunds) ? $refunds : [] as $refund) {
            if (is_array($refund) && ($refund['refund_key'] ?? null) === $key) {
                return $refund;
            }
        }

        return null;
    }

    private function audit(object $intent, string $event, array $context = []): void
    {
        // refund_key and reason stay in the database; the log carries only bounded identifiers.
        Log::info('payment.gateway_refund', ['payment_id' => $intent->payment_id, 'refund_id' => $intent->id, 'event' => $event,
            'environment' => $intent->environment, 'amount' => (int) $intent->amount, 'operator' => $context['operator'] ?? $intent->operator] + $context);
    }

    private function rupiahValue(mixed $value): ?int
    {
        return (is_string($value) || is_int($value)) && preg_match('/\A[0-9]{1,9}(?:\.00)?\z/', (string) $value) ? (int) $value : null;
    }

    private function rupiah(int $value): string
    {
        return 'Rp'.number_format($value, 0, ',', '.');
    }
}
