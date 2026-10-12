<?php

namespace App\Services;

use App\Models\RecipeSearch;
use App\Support\RecipeMode;
use Illuminate\Support\Facades\DB;

class SearchHistoryService
{
    public function record(string $visitor, array $snapshot): void
    {
        DB::transaction(function () use ($visitor, $snapshot) {
            RecipeSearch::create(['visitor_id' => $visitor, 'ingredients' => $snapshot['input']['ingredients'],
                'preferences' => ['mode' => $snapshot['input']['mode'] ?? RecipeMode::NORMAL, 'servings' => $snapshot['input']['servings'] ?? 1, 'preference' => $snapshot['input']['preference'] ?? 'bebas'],
                'recipe_count' => count($snapshot['recipes']), 'snapshot_id' => $snapshot['id']]);
            $old = RecipeSearch::where('visitor_id', $visitor)->latest('id')->skip(30)->take(PHP_INT_MAX)->pluck('id');
            RecipeSearch::where('visitor_id', $visitor)->whereIn('id', $old)->delete();
        });
    }
}
