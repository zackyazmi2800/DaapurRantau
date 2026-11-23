<?php

namespace App\Services;

use App\Exceptions\RecommendationOperationException;
use Illuminate\Support\Facades\DB;

class QuotaService
{
    public function __construct(private SubscriptionService $subscriptions) {}

    // Caller holds the account write lock until it saves the reserved operation.
    public function reserve(int $userId): int
    {
        $day = ['user_id' => $userId, 'usage_date' => now('Asia/Jakarta')->toDateString()];
        DB::table('daily_usages')->insertOrIgnore($day);
        $usage = DB::table('daily_usages')->where($day)->first();
        $reserved = DB::table('recommendation_operations')->where('daily_usage_id', $usage->id)
            ->where('status', 'reserved')->count();
        $limit = $this->subscriptions->active($userId) ? 30 : 1;
        if ($usage->successful_count + $reserved >= $limit) {
            throw new RecommendationOperationException($reserved
                ? 'Kuota sedang digunakan. Tunggu hingga pencarian selesai.'
                : "Kuota {$limit} pencarian hari ini sudah habis. Kuota tersedia kembali pukul 00.00 WIB.", 429);
        }

        return $usage->id;
    }

    public function complete(int $usageId): void
    {
        DB::table('daily_usages')->where('id', $usageId)->increment('successful_count');
    }
}
