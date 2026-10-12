<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SavedRecipe extends Model
{
    protected $fillable = ['visitor_id', 'recipe_title', 'recipe_data', 'recipe_fingerprint'];

    protected function casts(): array
    {
        return ['recipe_data' => 'array'];
    }
}
