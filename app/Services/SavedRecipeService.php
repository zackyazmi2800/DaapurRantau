<?php

namespace App\Services;

use App\Models\SavedRecipe;
use Illuminate\Support\Arr;

class SavedRecipeService
{
    public function data(array $recipe): array
    {
        return Arr::only($recipe, ['title', 'description', 'cook_time_minutes', 'servings', 'difficulty', 'ingredients', 'steps', 'tips']);
    }

    public function fingerprint(array $recipe): string
    {
        $canonical = function (array $value) use (&$canonical): array {
            if (! array_is_list($value)) {
                ksort($value);
            }
            foreach ($value as &$item) {
                if (is_array($item)) {
                    $item = $canonical($item);
                }
            }

            return $value;
        };

        return hash('sha256', json_encode($canonical($this->data($recipe)), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    public function save(int $userId, array $recipe): SavedRecipe
    {
        return SavedRecipe::firstOrCreate(['user_id' => $userId, 'recipe_fingerprint' => $this->fingerprint($recipe)],
            ['recipe_title' => $recipe['title'], 'recipe_data' => $this->data($recipe)]);
    }

    public function mark(int $userId, array $recipes): array
    {
        $fingerprints = array_map($this->fingerprint(...), $recipes);
        $saved = SavedRecipe::where('user_id', $userId)->whereIn('recipe_fingerprint', $fingerprints)->pluck('id', 'recipe_fingerprint');
        foreach ($recipes as &$recipe) {
            $recipe['favorite_id'] = $saved[$this->fingerprint($recipe)] ?? null;
        }

        return $recipes;
    }
}
