<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * payments:refund. Before a transfer made outside Midtrans is recorded, the current Get Status is
 * fetched so refunds created in the Midtrans Dashboard (with the webhook off) are reconciled first
 * and the remaining amount is computed from individual refunds[] entries. Never sends a refund;
 * any doubt about the gateway state fails closed.
 */
class ManualRefundService
{
    public function __construct(private MidtransSandboxService $gateway, private GatewayRefundService $refunds, private PaymentAdjustmentService $adjustments) {}

    public function refund(int $paymentId, mixed $amount, mixed $reason, mixed $reference, bool $confirm): array
    {
        $payment = DB::table('payments')->where('id', $paymentId)->first();
        if (! $payment) {
            return [false, 'Pembayaran tidak ditemukan.'];
        }
        if ($error = $this->adjustments->manualRefundInputError($amount, $reason, $reference)) {
            return [false, $error];
        }
        $listed = null;
        // Only paid payments can take a manual refund; every other state keeps its existing local answer.
        if ($payment->status === 'paid') {
            [$error, $listed] = $this->reconcile($payment);
            if ($error !== null) {
                return [false, $error.' Tidak ada yang dicatat; sisa refund tidak dianggap tersedia.'];
            }
        }

        return $this->adjustments->manualRefund($paymentId, $amount, $reason, $reference, $confirm, $listed);
    }

    /** @return array{0: ?string, 1: ?array<string, int>} */
    private function reconcile(object $payment): array
    {
        if ($this->gateway->environment() === null || ! $this->gateway->serverKeyReady()) {
            return ['Konfigurasi Midtrans belum lengkap, sehingga refund dari Dashboard Midtrans tidak dapat diperiksa.', null];
        }
        try {
            $current = $this->gateway->currentStatus($payment);
        } catch (HttpException) {
            return ['Status transaksi Midtrans tidak dapat diverifikasi (gagal, timeout, atau tidak sesuai dengan pembayaran).', null];
        }
        if ($current === null) {
            return ['Transaksi tidak ditemukan di Midtrans.', null];
        }
        $status = $current['transaction_status'] ?? null;
        if (! in_array($status, ['settlement', 'capture', 'partial_refund', 'refund'], true)) {
            return ['Status transaksi Midtrans ('.(is_string($status) ? $status : 'tidak dikenal').') tidak dapat dipakai untuk menghitung sisa refund.', null];
        }
        $refunds = $current['refunds'] ?? [];
        if (! is_array($refunds) || ! array_is_list($refunds)) {
            return ['Rincian refunds[] dari Midtrans tidak valid.', null];
        }
        $listed = [];
        foreach ($refunds as $refund) {
            $key = is_array($refund) ? ($refund['refund_key'] ?? null) : null;
            $value = is_array($refund) ? $this->rupiah($refund['refund_amount'] ?? null) : null;
            if (! is_string($key) || trim($key) === '' || $value === null) {
                return ['Rincian refunds[] dari Midtrans tidak lengkap (refund_key atau refund_amount individual).', null];
            }
            if (array_key_exists($key, $listed)) {
                return ['refunds[] dari Midtrans memuat refund_key yang sama lebih dari sekali.', null];
            }
            // Same amount with different refund_key values are separate refunds.
            $listed[$key] = $value;
        }
        $cumulative = array_key_exists('refund_amount', $current) ? $this->rupiah($current['refund_amount']) : 0;
        if ($cumulative === null || $cumulative !== array_sum($listed)) {
            return ['refund_amount kumulatif Midtrans tidak sama dengan jumlah refunds[] individual.', null];
        }
        if (in_array($status, ['partial_refund', 'refund'], true) && $listed === []) {
            return ['Midtrans menyatakan ada refund tetapi tidak memberikan rincian refunds[].', null];
        }
        try {
            $this->refunds->syncFromStatus($payment, $current);
        } catch (HttpException) {
            return ['Refund dari Midtrans tidak dapat dicatat ke buku refund (data tidak konsisten); tinjau manual.', null];
        }

        return [null, $listed];
    }

    private function rupiah(mixed $value): ?int
    {
        return (is_string($value) || is_int($value)) && preg_match('/\A[0-9]{1,9}(?:\.00)?\z/', (string) $value) ? (int) $value : null;
    }
}
