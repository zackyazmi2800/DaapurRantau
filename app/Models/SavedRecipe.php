<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SavedRecipe extends Model
{
    protected $fillable = ['visitor_id', 'user_id', 'recipe_title', 'recipe_data', 'recipe_fingerprint'];

    protected function casts(): array
    {
        return ['recipe_data' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
