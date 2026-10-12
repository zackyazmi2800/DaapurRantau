<?php

namespace App\Services;

use App\Models\SavedRecipe;
use Illuminate\Support\Arr;

class SavedRecipeService
{
    public function __construct(private RecipeImageService $images) {}

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

    public function save(string $visitor, array $recipe): SavedRecipe
    {
        return SavedRecipe::firstOrCreate(['visitor_id' => $visitor, 'recipe_fingerprint' => $this->fingerprint($recipe)],
            ['recipe_title' => $recipe['title'], 'recipe_data' => $this->data($recipe) + ['image' => $this->images->forDisplay($recipe['image'] ?? null)]]);
    }

    public function mark(string $visitor, array $recipes): array
    {
        $fingerprints = array_map($this->fingerprint(...), $recipes);
        $saved = SavedRecipe::where('visitor_id', $visitor)->whereIn('recipe_fingerprint', $fingerprints)->pluck('id', 'recipe_fingerprint');
        foreach ($recipes as &$recipe) {
            $recipe['favorite_id'] = $saved[$this->fingerprint($recipe)] ?? null;
        }

        return $recipes;
    }
}
