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
        $integerCounts = $driver === 'sqlite'
            ? "typeof(successful_count) = 'integer' AND typeof(reserved_count) = 'integer'"
            : '1 = 1';
        DB::statement("CREATE TABLE guest_quotas (
            id {$id},
            identity_hash CHAR(64) NOT NULL UNIQUE,
            successful_count INTEGER NOT NULL DEFAULT 0,
            reserved_count INTEGER NOT NULL DEFAULT 0,
            created_at TIMESTAMP NULL,
            updated_at TIMESTAMP NULL,
            CONSTRAINT guest_quota_counts CHECK (
                {$integerCounts} AND successful_count >= 0 AND reserved_count >= 0
                AND successful_count + reserved_count <= 3
            )
        )");
    }

    public function down(): void
    {
        Schema::dropIfExists('guest_quotas');
    }
};
