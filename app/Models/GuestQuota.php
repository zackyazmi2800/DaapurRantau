<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GuestQuota extends Model
{
    // Assign trusted fields explicitly in future domain services, never request arrays.
    protected $guarded = ['*'];

    protected $hidden = ['identity_hash'];

    protected function casts(): array
    {
        return ['successful_count' => 'integer', 'reserved_count' => 'integer'];
    }

    public function operations(): HasMany
    {
        return $this->hasMany(RecommendationOperation::class, 'guest_quota_id');
    }
}
