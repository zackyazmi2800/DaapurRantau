<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RecipeSearch extends Model
{
    protected $fillable = ['visitor_id', 'ingredients', 'preferences', 'recipe_count', 'snapshot_id'];

    protected function casts(): array
    {
        return ['ingredients' => 'array', 'preferences' => 'array', 'recipe_count' => 'integer'];
    }
}
