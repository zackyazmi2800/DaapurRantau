<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_gateway_refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->index()->constrained();
            $table->string('refund_key', 50)->unique();
            $table->unsignedInteger('amount');
            $table->string('reason');
            // pending, submitted, unknown, review (unresolved); succeeded, confirmed, rejected
            $table->string('status', 20)->default('pending')->index();
            $table->string('operator', 100);
            $table->string('environment', 20);
            // Equals payment_id while a request is unresolved: at most one in-flight refund per payment.
            $table->unsignedBigInteger('active_slot')->nullable()->unique();
            $table->string('midtrans_refund_chargeback_id', 100)->nullable();
            $table->string('midtrans_refund_chargeback_uuid', 100)->nullable();
            $table->string('refund_method', 30)->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('provider_status_code', 3)->nullable();
            $table->string('gateway_created_at', 32)->nullable();
            $table->string('bank_confirmed_at', 32)->nullable();
            // Explicit operator reattempts reuse the same refund_key (Midtrans: within 7 days).
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->dateTime('last_attempt_at')->nullable();
            $table->dateTime('submitted_at')->nullable();
            $table->dateTime('verified_at')->nullable();
            $table->dateTime('last_checked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // Financial history is never dropped by a rollback: archive (sqlite3 .backup) and restore instead.
        foreach (['payment_gateway_refunds'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException("Rollback dibatalkan: tabel {$table} berisi histori refund/pembayaran yang tidak boleh dihapus. Gunakan backup dan restore (docs/operasional-pembayaran.md bagian 9-10).");
            }
        }
        Schema::drop('payment_gateway_refunds');
    }
};
