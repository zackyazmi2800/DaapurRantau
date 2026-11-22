<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SubscriptionService
{
    public function active(int $userId): ?object
    {
        return DB::table('subscriptions')->where('user_id', $userId)
            ->where('ends_at', '>', now('UTC'))->first();
    }

    public function supports(int $planId): bool
    {
        return in_array(DB::table('subscription_plans')->where('id', $planId)->value('code'), ['weekly', 'monthly'], true);
    }

    // Called under the payment transaction, after claiming the payment exactly once.
    public function extend(object $payment): void
    {
        DB::table('users')->where('id', $payment->user_id)->update(['id' => DB::raw('id')]);
        if (DB::table('subscription_periods')->where('payment_id', $payment->id)->exists()) {
            return;
        }
        $active = $this->active($payment->user_id);
        $now = now('UTC')->startOfSecond();
        $base = $active ? Carbon::parse($active->ends_at, 'UTC') : $now->copy();
        $start = $base->copy();
        $base->setTimezone('Asia/Jakarta');
        $code = DB::table('subscription_plans')->where('id', $payment->plan_id)->value('code');
        $end = match ($code) {
            'weekly' => $base->addWeek(),
            'monthly' => $base->addMonthNoOverflow(),
        };
        DB::table('subscriptions')->updateOrInsert(['user_id' => $payment->user_id], [
            'plan_id' => $payment->plan_id, 'starts_at' => $active->starts_at ?? $now,
            'ends_at' => $end->utc(),
        ]);
        DB::table('subscription_periods')->insert(['payment_id' => $payment->id, 'user_id' => $payment->user_id,
            'starts_at' => $start, 'ends_at' => $end, 'created_at' => $now, 'updated_at' => $now]);
    }

    public function withdraw(int $paymentId, string $status): bool
    {
        $period = DB::table('subscription_periods')->where('payment_id', $paymentId)->first();
        if (! $period || in_array($period->status, ['revoked', $status], true)) {
            return false;
        }
        $now = now('UTC')->startOfSecond();
        if ($period->status === 'suspended') {
            return (bool) DB::table('subscription_periods')->where('id', $period->id)->update(['status' => 'revoked', 'updated_at' => $now]);
        }
        $end = Carbon::parse($period->ends_at, 'UTC');
        $cut = max(0, $end->getTimestamp() - max(Carbon::parse($period->starts_at, 'UTC')->getTimestamp(), $now->getTimestamp()));
        DB::table('subscription_periods')->where('id', $period->id)->update([
            'status' => $status, 'held_seconds' => $cut, 'ends_at' => $end->copy()->subSeconds($cut), 'updated_at' => $now,
        ]);
        if ($cut === 0) {
            return true;
        }
        $queued = DB::table('subscription_periods')->where('user_id', $period->user_id)->where('status', 'active')
            ->where('starts_at', '>=', $period->ends_at)->get();
        foreach ($queued as $later) {
            DB::table('subscription_periods')->where('id', $later->id)->update([
                'starts_at' => Carbon::parse($later->starts_at, 'UTC')->subSeconds($cut),
                'ends_at' => Carbon::parse($later->ends_at, 'UTC')->subSeconds($cut), 'updated_at' => $now,
            ]);
        }
        $summary = DB::table('subscriptions')->where('user_id', $period->user_id)->value('ends_at');
        DB::table('subscriptions')->where('user_id', $period->user_id)->update(['ends_at' => Carbon::parse($summary, 'UTC')->subSeconds($cut)]);

        return true;
    }

    public function restore(int $paymentId, bool $revoked = false): bool
    {
        $period = DB::table('subscription_periods')->where('payment_id', $paymentId)
            ->whereIn('status', $revoked ? ['suspended', 'revoked'] : ['suspended'])->first();
        if (! $period) {
            return false;
        }
        $now = now('UTC')->startOfSecond();
        $held = (int) $period->held_seconds;
        if ($held === 0) {
            return (bool) DB::table('subscription_periods')->where('id', $period->id)->update(['status' => 'active', 'held_seconds' => null, 'updated_at' => $now]);
        }
        $active = $this->active($period->user_id);
        $start = $active ? Carbon::parse($active->ends_at, 'UTC') : $now->copy();
        $end = $start->copy()->addSeconds($held);
        DB::table('subscription_periods')->where('id', $period->id)->update([
            'status' => 'active', 'held_seconds' => null, 'starts_at' => $start, 'ends_at' => $end, 'updated_at' => $now,
        ]);
        DB::table('subscriptions')->where('user_id', $period->user_id)->update(['starts_at' => $active->starts_at ?? $now, 'ends_at' => $end]);

        return true;
    }
}
