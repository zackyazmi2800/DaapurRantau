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

        // One row per event: "request" (first operator) or "approval" (second operator, closes the refund).
        Schema::create('payment_gateway_refund_closures', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 10);
            $table->foreignId('gateway_refund_id')->index()->constrained('payment_gateway_refunds');
            // Set on approvals; unique so a request can be approved at most once.
            $table->foreignId('request_id')->nullable()->unique()->constrained('payment_gateway_refund_closures');
            // Snapshot of what the Midtrans confirmation identified.
            $table->unsignedBigInteger('payment_id');
            $table->uuid('order_id');
            $table->string('refund_key', 50);
            $table->unsignedInteger('amount');
            $table->string('environment', 20);
            $table->string('midtrans_ticket', 100);
            $table->date('midtrans_confirmed_on');
            $table->string('reason', 255);
            $table->unsignedInteger('operator_uid');
            $table->string('operator_name', 64);
            $table->dateTime('closed_at')->nullable();
            $table->dateTime('created_at');
        });

        foreach (['UPDATE', 'DELETE'] as $event) {
            DB::unprepared('CREATE TRIGGER payment_gateway_refund_closures_no_'.strtolower($event)."
                BEFORE {$event} ON payment_gateway_refund_closures
                BEGIN SELECT RAISE(ABORT, 'payment_gateway_refund_closures is append-only'); END");
        }
        // Historical refund facts never change and refund rows are never deleted.
        DB::unprepared("CREATE TRIGGER payment_gateway_refunds_immutable
            BEFORE UPDATE ON payment_gateway_refunds
            WHEN NEW.payment_id IS NOT OLD.payment_id OR NEW.refund_key IS NOT OLD.refund_key OR NEW.amount IS NOT OLD.amount
                OR NEW.reason IS NOT OLD.reason OR NEW.environment IS NOT OLD.environment OR NEW.operator IS NOT OLD.operator
                OR NEW.created_at IS NOT OLD.created_at
            BEGIN SELECT RAISE(ABORT, 'payment_gateway_refunds history is immutable'); END");
        DB::unprepared("CREATE TRIGGER payment_gateway_refunds_no_delete
            BEFORE DELETE ON payment_gateway_refunds
            BEGIN SELECT RAISE(ABORT, 'payment_gateway_refunds history is immutable'); END");
        // An administrative closure is final; only Midtrans later listing the refund may move it on.
        DB::unprepared("CREATE TRIGGER payment_gateway_refunds_closed_final
            BEFORE UPDATE OF status ON payment_gateway_refunds
            WHEN OLD.status = 'closed_unprocessed' AND NEW.status NOT IN ('closed_unprocessed', 'succeeded', 'confirmed', 'review')
            BEGIN SELECT RAISE(ABORT, 'closed refunds can only move to a status listed by Midtrans'); END");
    }

    public function down(): void
    {
        foreach (['payment_gateway_refunds_closed_final', 'payment_gateway_refunds_no_delete', 'payment_gateway_refunds_immutable'] as $trigger) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
        }
        Schema::drop('payment_gateway_refund_closures');
    }
};
