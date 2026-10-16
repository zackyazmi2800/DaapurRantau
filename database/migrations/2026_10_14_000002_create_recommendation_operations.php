<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();
        if (! in_array($driver, ['sqlite', 'mysql'], true)) {
            throw new RuntimeException('Migration ini hanya mendukung SQLite dan MySQL.');
        }
        $id = $driver === 'sqlite' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY';
        $foreign = $driver === 'sqlite' ? 'INTEGER' : 'BIGINT UNSIGNED';
        DB::statement("CREATE TABLE recommendation_operations (
            id {$id},
            guest_quota_id {$foreign} NULL,
            user_id {$foreign} NULL,
            idempotency_key CHAR(64) NOT NULL,
            input_hash CHAR(64) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            generation INTEGER NOT NULL DEFAULT 0,
            lease_token CHAR(64) NULL,
            lease_expires_at TIMESTAMP NULL,
            result_id CHAR(36) NULL UNIQUE,
            result_payload JSON NULL,
            result_expires_at TIMESTAMP NULL,
            completed_at TIMESTAMP NULL,
            created_at TIMESTAMP NULL,
            updated_at TIMESTAMP NULL,
            FOREIGN KEY (guest_quota_id) REFERENCES guest_quotas(id) ON DELETE RESTRICT,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
            CONSTRAINT operation_owner CHECK (
                (guest_quota_id IS NOT NULL AND user_id IS NULL)
                OR (guest_quota_id IS NULL AND user_id IS NOT NULL)
            ),
            CONSTRAINT operation_status CHECK (status IN ('pending', 'reserved', 'succeeded', 'failed', 'expired')),
            CONSTRAINT operation_generation CHECK (generation >= 0),
            CONSTRAINT operation_reservation CHECK (status <> 'reserved' OR (generation > 0 AND lease_token IS NOT NULL AND lease_expires_at IS NOT NULL)),
            CONSTRAINT operation_success CHECK (status <> 'succeeded' OR (result_id IS NOT NULL AND completed_at IS NOT NULL)),
            UNIQUE (guest_quota_id, idempotency_key),
            UNIQUE (user_id, idempotency_key)
        )");
        // Result payload lives on its owning operation, never in guest search history.
        // Planned retention: payload 24 hours; retain the idempotency tombstone
        // for the principal's lifetime. No automatic cleanup is installed here.
        DB::statement('CREATE INDEX operations_lease_index ON recommendation_operations (status, lease_expires_at)');
        DB::statement('CREATE INDEX operations_result_expiry_index ON recommendation_operations (result_expires_at)');
    }

    public function down(): void
    {
        Schema::dropIfExists('recommendation_operations');
    }
};
