<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->unsignedInteger('price');
        });
        DB::table('subscription_plans')->insert([
            ['code' => 'weekly', 'name' => 'Premium Mingguan', 'price' => 5000],
            ['code' => 'monthly', 'name' => 'Premium Bulanan', 'price' => 15000],
        ]);
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained();
            $table->foreignId('plan_id')->constrained('subscription_plans');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
        });
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->uuid('order_id')->unique();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('plan_id')->constrained('subscription_plans');
            $table->unsignedInteger('amount');
            $table->string('status')->default('pending');
            $table->string('snap_token')->nullable();
            $table->timestamps();
        });
        Schema::create('daily_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->date('usage_date');
            $table->unsignedInteger('successful_count')->default(0);
            $table->unique(['user_id', 'usage_date']);
        });
        Schema::table('recommendation_operations', function (Blueprint $table) {
            $table->unsignedBigInteger('daily_usage_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        // Financial history is never dropped by a rollback: archive (sqlite3 .backup) and restore instead.
        foreach (['payments'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException("Rollback dibatalkan: tabel {$table} berisi histori refund/pembayaran yang tidak boleh dihapus. Gunakan backup dan restore (docs/operasional-pembayaran.md bagian 9-10).");
            }
        }
        Schema::table('recommendation_operations', function (Blueprint $table) {
            $table->dropIndex(['daily_usage_id']);
        });
        Schema::table('recommendation_operations', function (Blueprint $table) {
            $table->dropColumn('daily_usage_id');
        });
        Schema::drop('daily_usages');
        Schema::drop('payments');
        Schema::drop('subscriptions');
        Schema::drop('subscription_plans');
    }
};
