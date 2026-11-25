<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->unique()->constrained();
            $table->foreignId('user_id')->index()->constrained();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('status')->default('active');
            $table->unsignedInteger('held_seconds')->nullable();
            $table->timestamps();
        });
        Schema::create('payment_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained();
            $table->string('kind');
            $table->string('source');
            $table->string('status')->default('pending_review');
            $table->unsignedInteger('amount')->nullable();
            $table->string('reference');
            $table->string('reason')->nullable();
            $table->timestamps();
            $table->unique(['payment_id', 'source', 'reference']);
        });

        foreach (DB::table('subscriptions')->orderBy('user_id')->get() as $subscription) {
            $periods = [];
            $first = $end = null;
            $paid = DB::table('payments')->join('subscription_plans', 'subscription_plans.id', '=', 'payments.plan_id')
                ->where('payments.user_id', $subscription->user_id)->where('payments.status', 'paid')
                ->orderBy('payments.updated_at')->orderBy('payments.id')->get(['payments.id', 'payments.updated_at', 'subscription_plans.code']);
            foreach ($paid as $payment) {
                $at = Carbon::parse($payment->updated_at, 'UTC');
                $start = $end && $end->gt($at) ? $end->copy() : $at->copy();
                $first = $end && $end->gt($at) ? $first : $at->copy();
                $local = $start->copy()->setTimezone('Asia/Jakarta');
                $end = match ($payment->code) {
                    'weekly' => $local->addWeek()->utc(),
                    'monthly' => $local->addMonthNoOverflow()->utc(),
                    default => null,
                };
                if ($end === null) {
                    break;
                }
                $periods[] = ['payment_id' => $payment->id, 'user_id' => $subscription->user_id, 'starts_at' => $start->toDateTimeString(),
                    'ends_at' => $end->toDateTimeString(), 'created_at' => $at->toDateTimeString(), 'updated_at' => $at->toDateTimeString()];
            }
            if ($periods !== [] && $end && $first->toDateTimeString() === $subscription->starts_at && $end->toDateTimeString() === $subscription->ends_at) {
                DB::table('subscription_periods')->insert($periods);
            } elseif ($paid->isNotEmpty()) {
                Log::warning('payment.periods_not_backfilled', ['user_id' => $subscription->user_id]);
            }
        }
    }

    public function down(): void
    {
        // Financial history is never dropped by a rollback: archive (sqlite3 .backup) and restore instead.
        foreach (['payment_adjustments', 'subscription_periods'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException("Rollback dibatalkan: tabel {$table} berisi histori refund/pembayaran yang tidak boleh dihapus. Gunakan backup dan restore (docs/operasional-pembayaran.md bagian 9-10).");
            }
        }
        Schema::drop('payment_adjustments');
        Schema::drop('subscription_periods');
    }
};
