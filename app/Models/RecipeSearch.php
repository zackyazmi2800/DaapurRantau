<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecipeSearch extends Model
{
    protected $fillable = ['visitor_id', 'ingredients', 'preferences', 'recipe_count', 'snapshot_id'];

    protected function casts(): array
    {
        return ['ingredients' => 'array', 'preferences' => 'array', 'recipe_count' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
