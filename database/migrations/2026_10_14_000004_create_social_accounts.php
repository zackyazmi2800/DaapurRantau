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
        DB::statement("CREATE TABLE social_accounts (
            id {$id},
            user_id {$foreign} NOT NULL,
            provider VARCHAR(32) NOT NULL,
            provider_subject VARCHAR(191) NOT NULL,
            created_at TIMESTAMP NULL,
            updated_at TIMESTAMP NULL,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
            UNIQUE (provider, provider_subject)
        )");
        DB::statement('CREATE INDEX social_accounts_user_index ON social_accounts (user_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('social_accounts');
    }
};
