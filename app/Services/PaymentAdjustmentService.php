<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentAdjustmentService
{
    public function __construct(private SubscriptionService $subscriptions) {}

    public function record(object $payment, string $kind, string $source, ?int $amount, string $reference, ?string $reason = null): string
    {
        [$outcome, $status] = DB::transaction(function () use ($payment, $kind, $source, $amount, $reference, $reason) {
            DB::table('users')->where('id', $payment->user_id)->update(['id' => DB::raw('id')]);
            $current = DB::table('payments')->where('id', $payment->id)->value('status');
            $key = ['payment_id' => $payment->id, 'source' => $source, 'reference' => $reference];
            if ($current === 'refunded' || DB::table('payment_adjustments')->where($key)->exists()) {
                return ['unchanged', null];
            }
            $settled = in_array($current, ['paid', 'chargeback'], true);
            $untracked = $settled && ! DB::table('subscription_periods')->where('payment_id', $payment->id)->exists();
            $status = $kind === 'refund' && ! $untracked ? 'resolved' : 'pending_review';
            $now = now();
            if ($kind === 'refund') {
                DB::table('payment_adjustments')->where('payment_id', $payment->id)->where('status', 'pending_review')
                    ->update(['status' => 'resolved', 'updated_at' => $now]);
            }
            DB::table('payment_adjustments')->insert($key + ['kind' => $kind, 'status' => $status, 'amount' => $amount,
                'reason' => $reason, 'created_at' => $now, 'updated_at' => $now]);
            if (in_array($kind, ['refund', 'chargeback'], true)) {
                DB::table('payments')->where('id', $payment->id)->update(['status' => $kind === 'refund' ? 'refunded' : 'chargeback', 'updated_at' => $now]);
            }
            if ($kind !== 'partial_refund') {
                $this->subscriptions->withdraw($payment->id, $kind === 'refund' ? 'revoked' : 'suspended');
            }

            return [match ($kind) {
                'refund' => 'refunded',
                'chargeback' => 'chargeback',
                default => 'disputed',
            }, $status];
        }, 3);
        if ($status !== null) {
            Log::warning('payment.adjustment', ['payment_id' => $payment->id, 'kind' => $kind, 'source' => $source, 'status' => $status]);
        }

        return $outcome;
    }

    public function close(object $payment, bool $restore): int
    {
        $closed = DB::transaction(function () use ($payment, $restore) {
            DB::table('users')->where('id', $payment->user_id)->update(['id' => DB::raw('id')]);
            $open = DB::table('payment_adjustments')->where('payment_id', $payment->id)->where('status', 'pending_review');
            $kinds = $open->pluck('kind');
            if ($kinds->isEmpty()) {
                return 0;
            }
            $now = now();
            $open->update(['status' => $restore ? 'rejected' : 'resolved', 'updated_at' => $now]);
            if ($restore) {
                $this->subscriptions->restore($payment->id);
                DB::table('payments')->where('id', $payment->id)->where('status', 'chargeback')->update(['status' => 'paid', 'updated_at' => $now]);
            } else {
                $this->subscriptions->withdraw($payment->id, 'revoked');
                if ($kinds->contains('reversal')) {
                    DB::table('payments')->where('id', $payment->id)->where('status', 'paid')->update(['status' => 'failed', 'updated_at' => $now]);
                }
            }

            return $kinds->count();
        }, 3);
        if ($closed > 0) {
            Log::warning('payment.review_closed', ['payment_id' => $payment->id, 'decision' => $restore ? 'restore' : 'revoke', 'closed' => $closed]);
        }

        return $closed;
    }

    public function manualRefund(int $paymentId, mixed $amount, mixed $reason, mixed $reference, bool $confirm): array
    {
        $payment = DB::table('payments')->where('id', $paymentId)->first();
        if (! $payment) {
            return [false, 'Pembayaran tidak ditemukan.'];
        }
        if (! is_string($amount) || ! ctype_digit($amount) || (int) $amount < 1 || strlen($amount) > 9) {
            return [false, 'Opsi --amount wajib berupa bilangan bulat rupiah yang lebih dari nol.'];
        }
        if (! is_string($reason) || trim($reason) === '' || mb_strlen($reason) > 255) {
            return [false, 'Opsi --reason wajib diisi (maksimal 255 karakter).'];
        }
        if (! is_string($reference) || trim($reference) === '' || mb_strlen($reference) > 100) {
            return [false, 'Opsi --reference wajib diisi dengan referensi transfer (maksimal 100 karakter).'];
        }
        $amount = (int) $amount;
        $reference = trim($reference);
        $plan = fn () => $this->planManualRefund($payment->id, $amount, trim($reason), $reference, $confirm);

        return $confirm ? DB::transaction(function () use ($payment, $plan) {
            DB::table('users')->where('id', $payment->user_id)->update(['id' => DB::raw('id')]);

            return $plan();
        }, 3) : $plan();
    }

    private function planManualRefund(int $paymentId, int $amount, string $reason, string $reference, bool $confirm): array
    {
        $payment = DB::table('payments')->where('id', $paymentId)->first();
        $manual = DB::table('payment_adjustments')->where(['payment_id' => $payment->id, 'source' => 'manual']);
        $recorded = (clone $manual)->where('reference', $reference)->first();
        if ($recorded) {
            return (int) $recorded->amount === $amount
                ? [true, 'Referensi ini sudah dicatat untuk pembayaran tersebut. Tidak ada perubahan.']
                : [false, 'Referensi ini sudah dicatat dengan nominal berbeda.'];
        }
        if ($payment->status !== 'paid') {
            return [false, 'Hanya pembayaran berstatus paid yang dapat dicatat refund manualnya.'];
        }
        $total = (int) $manual->sum('amount') + $amount;
        if ($total > $payment->amount) {
            return [false, 'Total refund melebihi nominal pembayaran.'];
        }
        $full = $total === (int) $payment->amount;
        $plan = $full ? 'refund penuh: pembayaran menjadi refunded dan sisa Premium dari pembayaran ini dicabut'
            : 'refund sebagian: dicatat untuk ditinjau, masa aktif Premium tidak diubah';
        if (! $confirm) {
            return [true, "DRY-RUN, belum ada yang dicatat. Rencana: {$plan}. Jalankan ulang dengan --confirm hanya setelah transfer benar-benar dilakukan."];
        }
        $outcome = $this->record($payment, $full ? 'refund' : 'partial_refund', 'manual', $amount, $reference, $reason);

        return $outcome === 'unchanged' ? [true, 'Sudah dicatat sebelumnya. Tidak ada perubahan.'] : [true, "Dicatat ({$plan})."];
    }
}
