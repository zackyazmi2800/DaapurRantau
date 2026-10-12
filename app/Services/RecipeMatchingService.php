<?php

namespace App\Services;

use App\Exceptions\RecipeGenerationException;
use App\Support\RecipeMode;

class RecipeMatchingService
{
    private const ALIASES = ['cabe' => 'cabai', 'cabe merah' => 'cabai merah', 'cabe rawit' => 'cabai rawit', 'garlic' => 'bawang putih'];

    public function aliases(): array
    {
        return self::ALIASES;
    }

    public function normalize(string $name): string
    {
        $name = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $name)));

        return self::ALIASES[$name] ?? $name;
    }

    public function match(array $available, array $ingredients, array $avoided = []): array
    {
        $available = array_map($this->normalize(...), $available);
        $avoided = array_map($this->normalize(...), $avoided);
        $missing = $optional = $items = $unique = $presentItems = [];
        $combined = false;
        $conflict = false;
        $matched = $required = 0;
        foreach ($ingredients as $ingredient) {
            $name = $this->normalize($ingredient['name']);
            $combined = $combined || preg_match('/[,;\/＆&+]|\b(?:dan|atau|serta)\b/iu', $name);
            $present = in_array($name, $available, true);
            // For avoidance only, conservatively catch qualified names too.
            foreach ($avoided as $allergen) {
                if ($allergen !== '' && preg_match('/(?<![\pL\pN])'.preg_quote($allergen, '/').'(?![\pL\pN])/u', $name)) {
                    $conflict = true;
                }
            }
            $items[] = $ingredient + ['available' => $present];
            // Required wins over optional duplicates, independent of provider order.
            if (! isset($unique[$name]) || ! $ingredient['optional']) {
                $unique[$name] = $ingredient + ['available' => $present];
            }
        }
        foreach ($unique as $ingredient) {
            if ($ingredient['optional']) {
                $optional[] = $ingredient;
            } else {
                $required++;
                $matched += (int) $ingredient['available'];
                if ($ingredient['available']) {
                    $presentItems[] = $ingredient;
                } else {
                    $missing[] = $ingredient;
                }
            }
        }

        return ['ready' => $missing === [] && ! $conflict, 'blocked' => $conflict,
            'missing_ingredients_count' => count($missing), 'available' => $presentItems, 'combined' => (bool) $combined,
            'missing' => $missing, 'optional' => $optional, 'ingredients' => $items,
            'matched' => $matched, 'required' => $required];
    }

    public function strict(array $recipes, array $input): array
    {
        $avoided = $input['allergies'] ?? [];
        $avoided = is_array($avoided) ? $avoided : explode(',', $avoided);
        $valid = [];
        foreach ($recipes as $recipe) {
            $match = $this->match($input['ingredients'], $recipe['ingredients'], $avoided);
            if ($match['required'] === 0 || $match['missing_ingredients_count'] !== 0 || $match['blocked'] || $match['combined']) {
                continue;
            }
            $recipe['match'] = $match + ['category' => 'Tanpa Bahan Tambahan'];
            $valid[] = $recipe;
        }

        return $valid;
    }

    public function rank(array $recipes, array $input): array
    {
        if (RecipeMode::strict($input)) {
            return $this->strict($recipes, $input);
        }
        if (count($recipes) !== 3) {
            throw new RecipeGenerationException('invalid_recipe_count');
        }
        $avoided = $input['allergies'] ?? [];
        $avoided = is_array($avoided) ? $avoided : explode(',', $avoided);
        $ranked = [];
        foreach ($recipes as $index => $recipe) {
            $match = $this->match($input['ingredients'], $recipe['ingredients'], $avoided);
            if ($match['blocked']) {
                throw new RecipeGenerationException('allergen_conflict');
            }
            if ($match['combined']) {
                throw new RecipeGenerationException('invalid_ingredient_names', diagnostics: ['kind' => 'combined_ingredient', 'recipe_index' => $index]);
            }
            $recipe['match'] = $match;
            $ranked[] = $recipe;
        }
        // Safety/structure are validated before ranking. More owned required
        // ingredients is a deterministic tie-break, not a claim of culinary safety.
        usort($ranked, fn ($a, $b) => ($a['match']['missing_ingredients_count'] <=> $b['match']['missing_ingredients_count'])
            ?: ($b['match']['matched'] <=> $a['match']['matched']));
        if ($ranked[0]['match']['required'] === 0 || $ranked[0]['match']['missing_ingredients_count'] > 2) {
            throw new RecipeGenerationException('no_best_match');
        }
        $best = array_shift($ranked);
        $suitable = array_filter($ranked, fn ($recipe) => $recipe['match']['missing_ingredients_count'] <= 3);
        usort($suitable, fn ($a, $b) => ($b['match']['missing_ingredients_count'] <=> $a['match']['missing_ingredients_count'])
            ?: ($b['match']['matched'] <=> $a['match']['matched']));
        if ($suitable === []) {
            throw new RecipeGenerationException('incomplete_categories', diagnostics: ['missing_categories' => ['Cukup Cocok']]);
        }
        // Exclude both chosen recipes; optional ingredients never change the count.
        $remaining = array_values(array_filter($ranked, fn ($recipe) => $recipe !== $suitable[0]));
        $alternatives = array_values(array_filter($remaining, fn ($recipe) => $recipe['match']['missing_ingredients_count'] >= 4));
        if ($alternatives === []) {
            usort($remaining, fn ($a, $b) => ($b['match']['missing_ingredients_count'] <=> $a['match']['missing_ingredients_count'])
                ?: ($b['match']['matched'] <=> $a['match']['matched']));
            $alternatives = $remaining;
        }
        if ($alternatives === []) {
            throw new RecipeGenerationException('incomplete_categories', diagnostics: ['missing_categories' => ['Alternatif Menu']]);
        }
        $ranked = [$best, $suitable[0], $alternatives[0]];
        foreach (['Paling Cocok', 'Cukup Cocok', 'Alternatif Menu'] as $index => $category) {
            $ranked[$index]['match']['category'] = $category;
        }

        return $ranked;
    }
}
