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
        DB::statement("CREATE TABLE email_verification_challenges (
            id {$id},
            user_id {$foreign} NOT NULL,
            purpose VARCHAR(40) NOT NULL DEFAULT 'verify_email',
            otp_hash VARCHAR(255) NOT NULL,
            expires_at TIMESTAMP NOT NULL,
            verification_attempts INTEGER NOT NULL DEFAULT 0,
            resend_count INTEGER NOT NULL DEFAULT 0,
            last_sent_at TIMESTAMP NULL,
            consumed_at TIMESTAMP NULL,
            created_at TIMESTAMP NULL,
            updated_at TIMESTAMP NULL,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
            CONSTRAINT otp_attempts CHECK (verification_attempts >= 0 AND verification_attempts <= 5),
            CONSTRAINT otp_resends CHECK (resend_count >= 0),
            UNIQUE (user_id, purpose)
        )");
    }

    public function down(): void
    {
        Schema::dropIfExists('email_verification_challenges');
    }
};
