<?php

namespace App\Services;

use App\Models\RecipeSearch;
use App\Support\RecipeMode;

class SearchHistoryService
{
    public function recordUser(int $userId, array $snapshot, int $operationId): void
    {
        // Called inside the operation commit; anonymous legacy rows are untouched.
        $history = new RecipeSearch;
        $history->forceFill(['user_id' => $userId, 'visitor_id' => null,
            'recommendation_operation_id' => $operationId, 'ingredients' => $snapshot['input']['ingredients'],
            'preferences' => ['mode' => $snapshot['input']['mode'] ?? RecipeMode::NORMAL,
                'servings' => $snapshot['input']['servings'] ?? 1, 'preference' => $snapshot['input']['preference'] ?? 'bebas'],
            'recipe_count' => count($snapshot['recipes']), 'snapshot_id' => $snapshot['id']])->save();
    }
}
