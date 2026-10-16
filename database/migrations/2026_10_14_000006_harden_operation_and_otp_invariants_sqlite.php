<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            throw new RuntimeException('Migration lanjutan ini khusus SQLite; driver lain perlu review terpisah.');
        }

        // Do not infer/backfill identity or completion metadata on existing rows.
        // These checks execute only when an operator explicitly runs migrations.
        // A populated foundation requires a separate preservation/backfill plan.
        if (DB::table('recommendation_operations')->exists()
            || DB::table('email_verification_challenges')->exists()) {
            throw new RuntimeException('Fondasi operasi/OTP sudah berisi data; hentikan untuk rencana pelestarian terpisah.');
        }

        // Nullable ADD COLUMN avoids reconstruction. Triggers require a target
        // email for all new writes; old foundation migrations remain unchanged.
        DB::statement('ALTER TABLE email_verification_challenges ADD COLUMN target_email VARCHAR(255) NULL');

        $operationInvalid = <<<'SQL'
            typeof(NEW.generation) <> 'integer' OR NEW.generation < 0
            OR (NEW.status = 'succeeded' AND (
                NEW.result_id IS NULL OR length(trim(NEW.result_id)) = 0
                OR NEW.completed_at IS NULL OR julianday(NEW.completed_at) IS NULL
                OR NEW.result_expires_at IS NULL OR julianday(NEW.result_expires_at) IS NULL
                OR datetime(NEW.result_expires_at) <> datetime(NEW.completed_at, '+24 hours')
                OR (NEW.result_payload IS NULL AND julianday(NEW.result_expires_at) > julianday('now'))
            ))
SQL;
        $otpInvalid = <<<'SQL'
            typeof(NEW.verification_attempts) <> 'integer'
            OR NEW.verification_attempts < 0 OR NEW.verification_attempts > 5
            OR typeof(NEW.resend_count) <> 'integer' OR NEW.resend_count < 0
            OR NEW.target_email IS NULL OR length(trim(NEW.target_email)) = 0
            OR NEW.target_email <> lower(trim(NEW.target_email))
            OR NEW.expires_at IS NULL OR julianday(NEW.expires_at) IS NULL
            OR length(NEW.otp_hash) < 32
SQL;
        foreach (['INSERT', 'UPDATE'] as $event) {
            $suffix = strtolower($event);
            DB::unprepared("CREATE TRIGGER operations_invariant_{$suffix}
                BEFORE {$event} ON recommendation_operations
                WHEN {$operationInvalid}
                BEGIN SELECT RAISE(ABORT, 'Invalid operation metadata'); END");
            DB::unprepared("CREATE TRIGGER email_challenge_invariant_{$suffix}
                BEFORE {$event} ON email_verification_challenges
                WHEN {$otpInvalid}
                BEGIN SELECT RAISE(ABORT, 'Invalid email challenge metadata'); END");
        }

        // Initial success must persist a result. Later payload removal is allowed
        // only after 24 hours; the successful ledger identity remains unchanged.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER operations_success_insert
            BEFORE INSERT ON recommendation_operations
            WHEN NEW.status = 'succeeded' AND NEW.result_payload IS NULL
            BEGIN SELECT RAISE(ABORT, 'Successful operation needs a result'); END
SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER operations_success_update
            BEFORE UPDATE ON recommendation_operations
            WHEN (OLD.status <> 'succeeded' AND NEW.status = 'succeeded' AND NEW.result_payload IS NULL)
                OR (OLD.status = 'succeeded' AND (
                    NEW.status IS NOT OLD.status OR NEW.result_id IS NOT OLD.result_id
                    OR NEW.guest_quota_id IS NOT OLD.guest_quota_id OR NEW.user_id IS NOT OLD.user_id
                    OR NEW.idempotency_key IS NOT OLD.idempotency_key OR NEW.input_hash IS NOT OLD.input_hash
                    OR NEW.completed_at IS NOT OLD.completed_at OR NEW.result_expires_at IS NOT OLD.result_expires_at
                ))
            BEGIN SELECT RAISE(ABORT, 'Successful operation ledger must be preserved'); END
SQL);

        // New challenges must target the user's current normalized address.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER email_challenge_target_insert
            BEFORE INSERT ON email_verification_challenges
            WHEN NEW.consumed_at IS NOT NULL OR NOT EXISTS (
                SELECT 1 FROM users WHERE id = NEW.user_id AND lower(trim(email)) = NEW.target_email
            )
            BEGIN SELECT RAISE(ABORT, 'Email target mismatch'); END
SQL);
        // Rebinding or reissuing requires a different hash; verification must
        // still match the user's current address. Consumption/resend services
        // must use conditional updates and throttling, not blind model saves.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER email_challenge_target_update
            BEFORE UPDATE ON email_verification_challenges
            WHEN NEW.user_id IS NOT OLD.user_id OR NEW.purpose IS NOT OLD.purpose
                OR (OLD.consumed_at IS NOT NULL AND NEW.consumed_at IS NOT OLD.consumed_at AND NEW.consumed_at IS NOT NULL)
                OR ((NEW.target_email IS NOT OLD.target_email
                    OR (OLD.consumed_at IS NOT NULL AND NEW.consumed_at IS NULL))
                    AND NEW.otp_hash IS OLD.otp_hash)
                OR (OLD.consumed_at IS NULL AND NEW.consumed_at IS NOT NULL AND (
                    OLD.verification_attempts >= 5
                    OR julianday(OLD.expires_at) <= julianday('now')
                    OR NOT EXISTS (SELECT 1 FROM users WHERE id = NEW.user_id AND lower(trim(email)) = NEW.target_email)
                ))
                OR (NEW.otp_hash IS NOT OLD.otp_hash AND NOT EXISTS (
                    SELECT 1 FROM users WHERE id = NEW.user_id AND lower(trim(email)) = NEW.target_email
                ))
            BEGIN SELECT RAISE(ABORT, 'Email challenge cannot be rebound or consumed'); END
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Rollback invariant membutuhkan review terpisah; jangan melepas proteksi secara otomatis.');
    }
};
