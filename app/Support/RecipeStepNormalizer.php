<?php

namespace App\Support;

class RecipeStepNormalizer
{
    /** Normalize only for display; never rewrite stored favorites or their fingerprints. */
    public function normalize(array $steps): array
    {
        $result = [];
        foreach ($steps as $step) {
            $instruction = is_string($step) ? $step : ($step['instruction'] ?? null);
            if (! is_string($instruction) || trim($instruction) === '') {
                continue;
            }
            $number = count($result) + 1;
            $result[] = [
                'step_number' => $number,
                'title' => is_array($step) ? ($step['title'] ?? 'Langkah '.$number) : 'Langkah '.$number,
                'instruction' => $instruction,
                'duration_minutes' => is_array($step) ? ($step['duration_minutes'] ?? null) : null,
                'cooking_tip' => is_array($step) ? ($step['cooking_tip'] ?? null) : null,
            ];
        }

        return $result;
    }
}
