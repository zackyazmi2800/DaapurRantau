<?php

namespace App\Services;

use App\Support\PaymentAlert;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class PaymentAdjustmentService
{
    public function __construct(private SubscriptionService $subscriptions) {}

    public function record(object $payment, string $kind, string $source, ?int $amount, string $reference, ?string $reason = null): string
    {
        [$outcome, $status] = DB::transaction(function () use ($payment, $kind, $source, $amount, $reference, $reason) {
            DB::table('users')->where('id', $payment->user_id)->update(['id' => DB::raw('id')]);
            $current = DB::table('payments')->where('id', $payment->id)->value('status');
            $key = ['payment_id' => $payment->id, 'source' => $source, 'reference' => $reference];
            $settled = in_array($current, ['paid', 'chargeback'], true);
            if ($current === 'refunded' || ($kind === 'settlement' && $settled) || DB::table('payment_adjustments')->where($key)->exists()) {
                return ['unchanged', null];
            }
            $untracked = $settled && ! DB::table('subscription_periods')->where('payment_id', $payment->id)->exists();
            $status = $kind === 'refund' && ! $untracked ? 'resolved' : 'pending_review';
            $now = now();
            if ($kind === 'refund') {
                $this->refundInFull($payment->id, $now);
            }
            DB::table('payment_adjustments')->insert($key + ['kind' => $kind, 'status' => $status, 'amount' => $amount,
                'reason' => $reason, 'created_at' => $now, 'updated_at' => $now]);
            if ($kind === 'chargeback') {
                DB::table('payments')->where('id', $payment->id)->update(['status' => 'chargeback', 'updated_at' => $now]);
            }
            if (! in_array($kind, ['partial_refund', 'settlement', 'refund'], true)) {
                $this->subscriptions->withdraw($payment->id, 'suspended');
            }

            return [match ($kind) {
                'refund' => 'refunded',
                'chargeback' => 'chargeback',
                default => 'disputed',
            }, $status];
        }, 3);
        if ($status !== null) {
            DB::afterCommit(fn () => Log::warning('payment.adjustment', ['payment_id' => $payment->id, 'kind' => $kind, 'source' => $source, 'status' => $status]));
        }

        return $outcome;
    }

    private function refundInFull(int $paymentId, mixed $now): void
    {
        DB::table('payment_adjustments')->where('payment_id', $paymentId)->where('status', 'pending_review')
            ->update(['status' => 'resolved', 'updated_at' => $now]);
        DB::table('payments')->where('id', $paymentId)->update(['status' => 'refunded', 'updated_at' => $now]);
        $this->subscriptions->withdraw($paymentId, 'revoked');
    }

    private function refundRupiah(mixed $value, int $limit): ?int
    {
        if ((! is_string($value) && ! is_int($value))
            || ! preg_match('/\A[0-9]{1,9}(?:\.00)?\z/', (string) $value)) {
            return null;
        }
        $amount = (int) $value;

        return $amount > 0 && $amount < $limit ? $amount : null;
    }

    public function recordMidtransPartialRefund(object $payment, array $payload): string
    {
        $total = $this->refundRupiah($payload['refund_amount'] ?? null, (int) $payment->amount);
        $details = [];
        $refunds = array_key_exists('refunds', $payload) ? $payload['refunds'] : [];
        abort_unless(is_array($refunds) && array_is_list($refunds), 422);
        $usable = $refunds !== [];
        foreach ($refunds as $refund) {
            $key = is_array($refund) ? ($refund['refund_key'] ?? null) : null;
            $amount = is_array($refund) ? $this->refundRupiah($refund['refund_amount'] ?? null, (int) $payment->amount) : null;
            abort_unless(is_string($key) && trim($key) !== '' && $amount !== null, 422);
            // Hash the exact provider key to keep references bounded and private.
            $reference = 'partial_refund:key:'.hash('sha256', $key);
            abort_if(isset($details[$reference]) && $details[$reference]['amount'] !== $amount, 422);
            $details[$reference] = ['key' => $key, 'amount' => $amount, 'reason' => is_string($refund['reason'] ?? null)
                ? mb_substr($refund['reason'], 0, 255) : null];
        }
        if ($usable) {
            $sum = array_sum(array_column($details, 'amount'));
            abort_if($sum >= $payment->amount, 422);
            if (array_key_exists('refund_amount', $payload)) {
                abort_unless($total !== null && $sum === $total, 422);
            }
        } else {
            abort_unless($total !== null, 422);
            $details = ['partial_refund:cumulative' => ['amount' => $total, 'reason' => null]];
        }

        return DB::transaction(function () use ($payment, $details, $total) {
            DB::table('users')->where('id', $payment->user_id)->update(['id' => DB::raw('id')]);
            $current = DB::table('payments')->where('id', $payment->id)->value('status');
            $after = $current === 'refunded';
            $changed = [];
            foreach ($details as $reference => $detail) {
                if ($reference === 'partial_refund:cumulative' && $detail['amount'] <= $this->midtransRefundTotal($payment->id)) {
                    continue;
                }
                $query = DB::table('payment_adjustments')->where([
                    'payment_id' => $payment->id, 'source' => 'midtrans', 'reference' => $reference,
                ]);
                $existing = (clone $query)->first();
                if ($existing) {
                    if ($reference === 'partial_refund:cumulative') {
                        if ($detail['amount'] > $existing->amount) {
                            $query->update(['amount' => $detail['amount'], 'status' => 'pending_review', 'updated_at' => now()]);
                            $changed[] = $reference;
                        }
                    } else {
                        abort_unless((int) $existing->amount === $detail['amount'], 422);
                    }

                    continue;
                }
                // Without a cumulative total, only identical stored keys may replay.
                abort_if($total === null, 422);
                if ($after) {
                    DB::table('payment_adjustments')->insert(['payment_id' => $payment->id, 'source' => 'midtrans', 'reference' => $reference,
                        'kind' => 'partial_refund', 'status' => 'pending_review', 'amount' => $detail['amount'], 'reason' => $detail['reason'],
                        'created_at' => now(), 'updated_at' => now()]);
                    $changed[] = $reference;
                } elseif ($this->record($payment, 'partial_refund', 'midtrans', $detail['amount'], $reference, $detail['reason']) !== 'unchanged') {
                    $changed[] = $reference;
                }
            }
            $refunded = $this->refundTotal($payment->id);
            abort_if($refunded['midtrans'] >= $payment->amount, 422);

            return match (true) {
                $changed === [] => 'unchanged',
                $after => $this->flagRefundAnomaly($payment, $changed, 'Refund setelah refund penuh terdeteksi; periksa kemungkinan kelebihan refund.', 'payment.refund_after_full_refund'),
                $current === 'pending' => $this->flagRefundAnomaly($payment, $changed, 'Refund sebagian pada pembayaran pending terdeteksi; Premium tidak diaktifkan sampai ditinjau.', 'payment.refund_on_pending'),
                default => $this->settleRefundTotal($payment, $current, $refunded['total'], $changed, $details),
            };
        }, 3);
    }

    private function settleRefundTotal(object $payment, string $current, int $total, array $changed, array $details): string
    {
        $now = now();
        $notes = [];
        foreach ($changed as $reference) {
            $key = $details[$reference]['key'] ?? null;
            if ($key !== null && DB::table('payment_adjustments')->where(['payment_id' => $payment->id, 'source' => 'manual', 'reference' => $key])->exists()) {
                $notes[$reference][] = 'Referensi sama dengan refund manual; periksa kemungkinan tercatat ganda.';
            }
        }
        $blocked = $this->referenceConflictOpen($payment->id);
        $reached = $total >= $payment->amount && in_array($current, ['paid', 'chargeback'], true);
        $full = $reached && ! $blocked;
        $deferred = $reached && $blocked;
        $conflict = $notes !== [] || $deferred;
        $excess = $full ? $total - (int) $payment->amount : 0;
        $tracked = DB::table('subscription_periods')->where('payment_id', $payment->id)->exists();
        foreach ($deferred ? $changed : [] as $reference) {
            $notes[$reference][] = 'Refund penuh ditunda: konflik referensi manual dan Midtrans belum ditinjau.';
        }
        if ($full) {
            $this->refundInFull($payment->id, $now);
            $rupiah = fn (int $value) => 'Rp'.number_format($value, 0, ',', '.');
            $note = $excess > 0 ? "Kelebihan refund {$rupiah($excess)}: total manual dan Midtrans {$rupiah($total)} melebihi pembayaran {$rupiah((int) $payment->amount)}." : null;
            foreach ($excess > 0 || ! $tracked ? $changed : [] as $reference) {
                $notes[$reference][] = $note;
            }
        }
        foreach ($notes as $reference => $lines) {
            $query = DB::table('payment_adjustments')->where(['payment_id' => $payment->id, 'source' => 'midtrans', 'reference' => $reference]);
            $reason = (clone $query)->value('reason');
            $query->update(['status' => 'pending_review', 'updated_at' => $now,
                'reason' => mb_substr(implode(' ', array_filter([...$lines, $reason])), 0, 255) ?: null]);
        }
        DB::afterCommit(function () use ($payment, $full, $excess, $conflict, $tracked) {
            $context = ['payment_id' => $payment->id, 'source' => 'midtrans'];
            if ($full) {
                Log::warning('payment.adjustment', $context + ['kind' => 'refund', 'status' => $excess > 0 || ! $tracked ? 'pending_review' : 'resolved']);
            }
            if ($excess > 0) {
                Log::warning('payment.refund_excess', $context + ['status' => 'pending_review']);
            }
            if ($conflict) {
                Log::warning('payment.refund_reference_conflict', $context + ['status' => 'pending_review']);
            }
        });

        return $full ? 'refunded' : 'disputed';
    }

    private function flagRefundAnomaly(object $payment, array $changed, string $note, string $event): string
    {
        foreach ($changed as $reference) {
            $query = DB::table('payment_adjustments')->where(['payment_id' => $payment->id, 'source' => 'midtrans', 'reference' => $reference]);
            $reason = (string) (clone $query)->value('reason');
            $query->update(['status' => 'pending_review', 'updated_at' => now(),
                'reason' => str_starts_with($reason, $note) ? $reason : mb_substr(trim("$note $reason"), 0, 255)]);
        }
        DB::afterCommit(fn () => Log::warning($event, ['payment_id' => $payment->id, 'source' => 'midtrans', 'status' => 'pending_review']));

        return 'disputed';
    }

    private function referenceConflictOpen(int $paymentId): bool
    {
        $references = DB::table('payment_adjustments')->where(['payment_id' => $paymentId, 'source' => 'manual'])->pluck('reference')
            ->map(fn ($reference) => 'partial_refund:key:'.hash('sha256', $reference))->all();

        return $references !== [] && DB::table('payment_adjustments')
            ->where(['payment_id' => $paymentId, 'source' => 'midtrans', 'status' => 'pending_review'])->whereIn('reference', $references)->exists();
    }

    /**
     * The single refund ledger used for every refund limit (gateway and manual) and for excess detection.
     *
     * Midtrans-side refunds are counted once per refund_key: an amount listed in refunds[] (when the caller
     * passes the current Get Status), else the recorded Midtrans adjustment, else the gateway refund row.
     * Gateway refunds that Midtrans does not list (pending, submitted, unknown, closed_unprocessed) are
     * "reserved": releasing a refund slot never releases its reserved amount. "actual" is money that is
     * known to have moved (Midtrans + manual); "total" adds the reservations.
     *
     * @param  array<string, int>|null  $listed  refund_key => individual refunds[] amount from Get Status
     */
    public function refundExposure(object $payment, ?array $listed = null, ?string $exclude = null, bool $dedupeReferences = false): array
    {
        $keyed = [];
        $cumulative = 0;
        $full = false;
        $manual = [];
        foreach (DB::table('payment_adjustments')->where('payment_id', $payment->id)->whereIn('source', ['manual', 'midtrans'])->get(['source', 'kind', 'reference', 'amount']) as $row) {
            if ($row->source === 'manual') {
                $manual['partial_refund:key:'.hash('sha256', $row->reference)] = ($manual['partial_refund:key:'.hash('sha256', $row->reference)] ?? 0) + (int) $row->amount;
            } elseif ($row->kind === 'refund') {
                $full = true;
            } elseif ($row->kind === 'partial_refund' && str_starts_with($row->reference, 'partial_refund:key:')) {
                $keyed[$row->reference] = max($keyed[$row->reference] ?? 0, (int) $row->amount);
            } elseif ($row->kind === 'partial_refund') {
                // Legacy amount-based references and the cumulative fallback are snapshots of the gateway total.
                $cumulative = max($cumulative, (int) $row->amount);
            }
        }
        foreach ($listed ?? [] as $key => $amount) {
            $keyed['partial_refund:key:'.hash('sha256', (string) $key)] = (int) $amount;
        }
        $reserved = 0;
        if (Schema::hasTable('payment_gateway_refunds')) {
            foreach (DB::table('payment_gateway_refunds')->where('payment_id', $payment->id)->where('status', '!=', 'rejected')->get(['refund_key', 'amount', 'status']) as $intent) {
                $reference = 'partial_refund:key:'.hash('sha256', $intent->refund_key);
                if ($intent->refund_key === $exclude || isset($keyed[$reference])) {
                    continue;
                }
                if (in_array($intent->status, ['succeeded', 'confirmed', 'review'], true)) {
                    // Listed by Midtrans earlier even if no adjustment could be recorded (e.g. HTTP 422).
                    $keyed[$reference] = (int) $intent->amount;
                } else {
                    $reserved += (int) $intent->amount;
                }
            }
        }
        $gross = (int) $payment->amount;
        $midtrans = max(array_sum($keyed), $cumulative, $full ? $gross : 0);
        $manual = array_sum($dedupeReferences ? array_diff_key($manual, $keyed) : $manual);
        $actual = $midtrans + $manual;

        return ['manual' => $manual, 'midtrans' => $midtrans, 'reserved' => $reserved, 'actual' => $actual,
            'total' => $actual + $reserved, 'remaining' => $gross - $actual - $reserved, 'excess' => max(0, $actual - $gross)];
    }

    /**
     * Records (once per refunded total) and alerts when money known to have been refunded exceeds the
     * payment. History is never removed or rejected; the anomaly blocks further refunds for the payment.
     *
     * @param  array<string, int>|null  $listed
     */
    public function flagRefundExcess(object $payment, ?array $listed = null, string $source = 'midtrans'): bool
    {
        if (! Schema::hasTable('payment_refund_anomalies')) {
            return false;
        }
        // A manual reference equal to a Midtrans refund_key is a possible double record of one transfer
        // (payment.refund_reference_conflict): limits count both, excess detection counts it once.
        $exposure = $this->refundExposure($payment, $listed, null, true);
        if ($exposure['excess'] === 0) {
            return false;
        }
        $evidence = ['source' => $source, 'manual' => $exposure['manual'], 'midtrans' => $exposure['midtrans'], 'reserved' => $exposure['reserved'],
            'listed' => collect($listed ?? [])->mapWithKeys(fn ($amount, $key) => [hash('sha256', (string) $key) => (int) $amount])->all(),
            'manual_references' => DB::table('payment_adjustments')->where(['payment_id' => $payment->id, 'source' => 'manual'])->count()];
        $inserted = DB::table('payment_refund_anomalies')->insertOrIgnore([
            'payment_id' => $payment->id, 'kind' => 'refund_excess', 'gross_amount' => (int) $payment->amount, 'refunded_amount' => $exposure['actual'],
            'excess_amount' => $exposure['excess'], 'evidence' => json_encode($evidence), 'created_at' => now(),
        ]);
        if ($inserted) {
            PaymentAlert::raise('payment.refund_excess', ['payment_id' => $payment->id, 'gross' => (int) $payment->amount,
                'refunded' => $exposure['actual'], 'excess' => $exposure['excess'], 'source' => $source]);
        }

        return $inserted > 0;
    }

    /** @return array<string, int> refund_key => individual amount from Get Status refunds[] */
    public function listedRefunds(array $current): array
    {
        $listed = [];
        foreach (is_array($current['refunds'] ?? null) ? $current['refunds'] : [] as $refund) {
            $key = is_array($refund) ? ($refund['refund_key'] ?? null) : null;
            $value = is_array($refund) ? ($refund['refund_amount'] ?? null) : null;
            if (is_string($key) && (is_string($value) || is_int($value)) && preg_match('/\A[0-9]{1,9}(?:\.00)?\z/', (string) $value)) {
                $listed[$key] = (int) $value;
            }
        }

        return $listed;
    }

    public function refundAnomalyOpen(int $paymentId): bool
    {
        return Schema::hasTable('payment_refund_anomalies') && DB::table('payment_refund_anomalies')->where('payment_id', $paymentId)->exists();
    }

    private function gatewayRefundOpen(int $paymentId): bool
    {
        // Any unresolved gateway request may still move money; its amount is unknown to the local ledger.
        return Schema::hasTable('payment_gateway_refunds') && DB::table('payment_gateway_refunds')
            ->where('payment_id', $paymentId)->whereIn('status', ['pending', 'submitted', 'unknown', 'review'])->exists();
    }

    public function refundTotal(int $paymentId): array
    {
        $manual = (int) DB::table('payment_adjustments')->where(['payment_id' => $paymentId, 'source' => 'manual'])->sum('amount');
        $midtrans = $this->midtransRefundTotal($paymentId);

        return ['manual' => $manual, 'midtrans' => $midtrans, 'total' => $manual + $midtrans];
    }

    private function midtransRefundTotal(int $paymentId): int
    {
        $rows = DB::table('payment_adjustments')->where([
            'payment_id' => $paymentId, 'source' => 'midtrans', 'kind' => 'partial_refund',
        ])->get(['reference', 'amount']);
        $individual = 0;
        $cumulative = 0;
        foreach ($rows as $row) {
            if (str_starts_with($row->reference, 'partial_refund:key:')) {
                $individual += (int) $row->amount;
            } else {
                // Legacy amount-based references and the stable fallback are snapshots
                // of the same gateway total, including any overlapping individual rows.
                $cumulative = max($cumulative, (int) $row->amount);
            }
        }

        return max($individual, $cumulative);
    }

    public function close(object $payment, bool $restore, bool $confirm): array
    {
        $plan = fn () => $this->planClose($payment->id, $restore, $confirm);
        [$ok, $message, $closed] = $confirm ? DB::transaction(function () use ($payment, $plan) {
            DB::table('users')->where('id', $payment->user_id)->update(['id' => DB::raw('id')]);

            return $plan();
        }, 3) : $plan();
        if ($closed > 0) {
            DB::afterCommit(fn () => Log::warning('payment.review_closed', ['payment_id' => $payment->id, 'decision' => $restore ? 'restore' : 'revoke', 'closed' => $closed]));
        }

        return [$ok, $message];
    }

    private function planClose(int $paymentId, bool $restore, bool $confirm): array
    {
        $open = DB::table('payment_adjustments')->where('payment_id', $paymentId)->where('status', 'pending_review');
        $kinds = $open->pluck('kind');
        if ($kinds->isEmpty()) {
            return [true, 'Tidak ada peninjauan terbuka untuk pembayaran ini.', 0];
        }
        $period = DB::table('subscription_periods')->where('payment_id', $paymentId)->value('status');
        if ($period === null) {
            return $this->planCloseWithoutPeriod($paymentId, $open, $restore, $confirm);
        }
        $resettled = $kinds->contains('settlement');
        if ($restore && $period === 'revoked' && ! $resettled) {
            return [false, 'Periode Premium pembayaran ini sudah dicabut dan tidak dapat dipulihkan. Peninjauan tetap terbuka dan tidak ada yang diubah.', 0];
        }
        $count = $kinds->count();
        $plan = match (true) {
            $restore && $period === 'active' => 'Premium dari pembayaran ini dipertahankan',
            $restore => 'Premium dari pembayaran ini dipulihkan',
            $period === 'revoked' => 'periode Premium pembayaran ini sudah dicabut sebelumnya',
            default => 'sisa Premium dari pembayaran ini dicabut',
        };
        if (! $confirm) {
            return [true, "DRY-RUN, belum ada perubahan. Rencana: menutup {$count} peninjauan; {$plan}. Tambahkan --confirm untuk menjalankan.", 0];
        }
        $now = now();
        (clone $open)->where('kind', 'settlement')->update(['status' => $restore ? 'resolved' : 'rejected', 'updated_at' => $now]);
        $open->update(['status' => $restore ? 'rejected' : 'resolved', 'updated_at' => $now]);
        if ($restore) {
            $this->subscriptions->restore($paymentId, $resettled);
            DB::table('payments')->where('id', $paymentId)->whereIn('status', $resettled ? ['chargeback', 'failed'] : ['chargeback'])
                ->update(['status' => 'paid', 'updated_at' => $now]);
        } else {
            $this->subscriptions->withdraw($paymentId, 'revoked');
            if ($kinds->contains('reversal')) {
                DB::table('payments')->where('id', $paymentId)->where('status', 'paid')->update(['status' => 'failed', 'updated_at' => $now]);
            }
        }

        return [true, "Ditutup: {$count} peninjauan; {$plan}.", $count];
    }

    private function planCloseWithoutPeriod(int $paymentId, Builder $open, bool $restore, bool $confirm): array
    {
        $refundsOnly = ! (clone $open)->where(fn ($query) => $query->where('source', '!=', 'midtrans')->orWhere('kind', '!=', 'partial_refund'))->exists();
        if (! $refundsOnly || DB::table('payments')->where('id', $paymentId)->value('status') !== 'pending') {
            return [false, 'Tidak ada periode Premium yang tercatat untuk pembayaran ini. Peninjauan tetap terbuka dan tidak ada yang diubah.', 0];
        }
        if ($restore) {
            return [false, 'Pembayaran pending ini belum pernah mengaktifkan Premium, jadi tidak ada yang dapat dipulihkan. Peninjauan tetap terbuka dan tidak ada yang diubah.', 0];
        }
        $count = (clone $open)->count();
        $plan = "menutup {$count} peninjauan; pembayaran tetap pending tanpa periode Premium";
        if (! $confirm) {
            return [true, "DRY-RUN, belum ada perubahan. Rencana: {$plan}. Tambahkan --confirm untuk menjalankan.", 0];
        }
        (clone $open)->update(['status' => 'resolved', 'updated_at' => now()]);

        return [true, "Ditutup: {$count} peninjauan; pembayaran tetap pending tanpa periode Premium. Premium tidak diaktifkan.", $count];
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
        if (DB::table('payment_adjustments')->where(['payment_id' => $payment->id, 'source' => 'midtrans',
            'reference' => 'partial_refund:key:'.hash('sha256', $reference)])->exists()) {
            return [false, 'Referensi ini sudah tercatat sebagai refund Midtrans untuk pembayaran ini. Tidak ada yang dicatat.'];
        }
        if ($this->referenceConflictOpen($payment->id)) {
            return [false, 'Ada konflik referensi refund manual dan Midtrans yang belum ditinjau. Selesaikan dengan payments:review sebelum mencatat refund manual baru. Tidak ada yang dicatat.'];
        }
        if ($payment->status !== 'paid') {
            return [false, 'Hanya pembayaran berstatus paid yang dapat dicatat refund manualnya.'];
        }
        if ($this->refundAnomalyOpen($payment->id)) {
            return [false, 'Pembayaran ini memiliki anomali kelebihan refund (refund_excess). Refund baru diblokir sampai ditinjau. Tidak ada yang dicatat.'];
        }
        if ($this->gatewayRefundOpen($payment->id)) {
            return [false, 'Ada refund Midtrans (payments:gateway-refund) yang hasilnya belum pasti. Jalankan payments:gateway-refund --reconcile terlebih dahulu. Tidak ada yang dicatat.'];
        }
        // Same ledger as payments:gateway-refund, including amounts reserved by closed gateway refunds.
        $exposure = $this->refundExposure($payment);
        $left = $exposure['remaining'];
        if ($amount > $left) {
            return [false, 'Total refund melebihi nominal pembayaran. Sisa yang dapat direfund: Rp'.number_format(max(0, $left), 0, ',', '.')
                .($exposure['reserved'] > 0 ? ' (Rp'.number_format($exposure['reserved'], 0, ',', '.').' dicadangkan untuk refund Midtrans yang belum pasti atau ditutup administratif).' : '.')];
        }
        // While gateway refunds are reserved the payment is never treated as fully refunded.
        $full = $amount === $left && $exposure['reserved'] === 0;
        $plan = $full ? 'refund penuh: pembayaran menjadi refunded dan sisa Premium dari pembayaran ini dicabut'
            : 'refund sebagian: dicatat untuk ditinjau, masa aktif Premium tidak diubah';
        if (! $confirm) {
            return [true, "DRY-RUN, belum ada yang dicatat. Rencana: {$plan}. Jalankan ulang dengan --confirm hanya setelah transfer benar-benar dilakukan."];
        }
        $outcome = $this->record($payment, $full ? 'refund' : 'partial_refund', 'manual', $amount, $reference, $reason);

        return $outcome === 'unchanged' ? [true, 'Sudah dicatat sebelumnya. Tidak ada perubahan.'] : [true, "Dicatat ({$plan})."];
    }
}
