<?php

namespace App\Services;

use App\Models\EmailVerificationChallenge;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class EmailVerificationService
{
    public function __construct(private AuthMailService $mail) {}

    public function issue(User $user): void
    {
        $this->mail->mailer();
        $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $hash = Hash::make($otp);
        $email = DB::transaction(function () use ($user, $hash) {
            // First write serializes issuance and verification, including on SQLite.
            User::whereKey($user->id)->toBase()->update(['id' => DB::raw('id')]);
            $current = User::findOrFail($user->id);
            if ($current->email_verified_at) {
                throw ValidationException::withMessages(['otp' => 'Email sudah diverifikasi. Silakan masuk.']);
            }
            $challenge = EmailVerificationChallenge::where('user_id', $current->id)->where('purpose', 'verify_email')->first();
            if ($challenge?->last_sent_at?->gt(now()->subSeconds(60))) {
                throw ValidationException::withMessages(['otp' => 'Tunggu 60 detik sebelum meminta kode baru.']);
            }
            $challenge ??= new EmailVerificationChallenge;
            $challenge->forceFill(['user_id' => $current->id, 'purpose' => 'verify_email', 'target_email' => mb_strtolower(trim($current->email)), 'otp_hash' => $hash, 'expires_at' => now()->addMinutes(5), 'verification_attempts' => 0, 'resend_count' => ($challenge->resend_count ?? -1) + 1, 'last_sent_at' => now(), 'consumed_at' => null])->save();

            return $challenge->target_email;
        });
        try {
            // Email delivery is outside the transaction and never automatically retried.
            $this->mail->otp($email, $otp);
        } catch (\Throwable) {
            EmailVerificationChallenge::where('user_id', $user->id)->where('otp_hash', $hash)->whereNull('consumed_at')->update(['expires_at' => now()]);
            throw ValidationException::withMessages(['otp' => 'Email belum berhasil dikirim. Tunggu 60 detik, lalu kirim ulang kode.']);
        }
    }

    public function verify(User $user, string $otp): bool
    {
        return DB::transaction(function () use ($user, $otp) {
            User::whereKey($user->id)->toBase()->update(['id' => DB::raw('id')]);
            $current = User::findOrFail($user->id);
            $challenge = EmailVerificationChallenge::where('user_id', $user->id)->where('purpose', 'verify_email')->first();
            if ($current->email_verified_at || ! $challenge || $challenge->consumed_at || $challenge->expires_at->lte(now()) || $challenge->verification_attempts >= 5 || $challenge->target_email !== mb_strtolower(trim($current->email))) {
                return false;
            }
            if (! Hash::check($otp, $challenge->otp_hash)) {
                // Return rather than throw: failed attempts must be committed.
                $challenge->increment('verification_attempts');
                return false;
            }
            $changed = EmailVerificationChallenge::whereKey($challenge->id)->where('otp_hash', $challenge->otp_hash)->whereNull('consumed_at')->where('verification_attempts', '<', 5)->where('expires_at', '>', now())->update(['consumed_at' => now()]);
            if ($changed !== 1) { return false; }
            $current->forceFill(['email_verified_at' => now()])->save();
            return true;
        });
    }
}
