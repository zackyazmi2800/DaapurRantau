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
}
