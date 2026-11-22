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
        $active = $this->active($payment->user_id);
        $now = now('UTC')->startOfSecond();
        $base = $active ? Carbon::parse($active->ends_at, 'UTC') : $now->copy();
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
    }
}
