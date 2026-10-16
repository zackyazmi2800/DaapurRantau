<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailVerificationChallenge extends Model
{
    // Assign trusted fields explicitly in future domain services, never request arrays.
    protected $guarded = ['*'];

    protected $hidden = ['otp_hash', 'target_email'];

    protected function casts(): array
    {
        return ['verification_attempts' => 'integer', 'resend_count' => 'integer', 'expires_at' => 'datetime', 'last_sent_at' => 'datetime', 'consumed_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
