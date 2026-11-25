<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only enforcement below uses SQLite triggers; other drivers need a reviewed equivalent.
        if (DB::getDriverName() !== 'sqlite') {
            throw new RuntimeException('Migration audit append-only ini khusus SQLite; driver lain perlu review terpisah.');
        }

        // Durable status history of every gateway refund, including closed refunds Midtrans lists later.
        Schema::create('payment_gateway_refund_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gateway_refund_id')->index()->constrained('payment_gateway_refunds');
            $table->unsignedBigInteger('payment_id')->index();
            $table->string('event', 40);
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();
            // Individual amount from refunds[] when Midtrans lists the refund_key.
            $table->unsignedInteger('listed_amount')->nullable();
            $table->string('operator', 100)->nullable();
            $table->dateTime('created_at');
        });

        // Refunds that exceed the payment: evidence is kept, further refunds for the payment are blocked.
        Schema::create('payment_refund_anomalies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->index()->constrained();
            $table->string('kind', 20);
            $table->unsignedInteger('gross_amount');
            $table->unsignedInteger('refunded_amount');
            $table->unsignedInteger('excess_amount');
            // Breakdown per source and refund_key hash; never credentials or customer data.
            $table->text('evidence');
            $table->dateTime('created_at');
            $table->unique(['payment_id', 'kind', 'refunded_amount']);
        });

        foreach (['payment_gateway_refund_events', 'payment_refund_anomalies'] as $table) {
            foreach (['UPDATE', 'DELETE'] as $event) {
                DB::unprepared("CREATE TRIGGER {$table}_no_".strtolower($event)."
                    BEFORE {$event} ON {$table}
                    BEGIN SELECT RAISE(ABORT, '{$table} is append-only'); END");
            }
        }
    }

    public function down(): void
    {
        Schema::drop('payment_refund_anomalies');
        Schema::drop('payment_gateway_refund_events');
    }
};
