<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SocialAccount extends Model
{
    // Assign trusted fields explicitly in future domain services, never request arrays.
    protected $guarded = ['*'];

    protected $hidden = ['provider_subject'];

    protected function casts(): array
    {
        return [];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
