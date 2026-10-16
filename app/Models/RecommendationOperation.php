<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class RecommendationOperation extends Model
{
    // Assign trusted fields explicitly in future domain services, never request arrays.
    protected $guarded = ['*'];

    protected $hidden = ['idempotency_key', 'input_hash', 'lease_token', 'result_payload'];

    protected function casts(): array
    {
        return ['generation' => 'integer', 'lease_expires_at' => 'datetime', 'result_expires_at' => 'datetime', 'completed_at' => 'datetime', 'result_payload' => 'array'];
    }

    public function guestQuota(): BelongsTo
    {
        return $this->belongsTo(GuestQuota::class, 'guest_quota_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function history(): HasOne
    {
        return $this->hasOne(RecipeSearch::class, 'recommendation_operation_id');
    }
}
