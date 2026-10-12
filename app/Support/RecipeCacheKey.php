<?php

namespace App\Support;

class RecipeCacheKey
{
    public function canonicalInput(array $input): array
    {
        return [
            'mode' => $input['mode'] ?? RecipeMode::NORMAL,
            'ingredients' => $this->normalizeList($input['ingredients']),
            'servings' => isset($input['servings']) ? (int) $input['servings'] : 1,
            'preference' => $input['preference'] ?? 'bebas',
            'allergies' => $this->normalizeList(explode(',', $input['allergies'] ?? '')),
        ];
    }

    public function make(array $input): string
    {
        $contract = [
            'input' => $this->canonicalInput($input),
            'model' => config('ai.gemini.model'),
            'prompt' => config('ai.prompt_version'),
            'schema' => config('ai.schema_version'),
            'thinking' => config('ai.gemini.thinking_level'),
            'output_limit' => config('ai.gemini.max_output_tokens'),
        ];
        if (! RecipeMode::strict($input)) {
            $contract['normal_recommendation'] = config('ai.normal_recommendation_version');
        }

        return 'recipes:'.hash('sha256', json_encode($contract, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    private function normalizeList(array $items): array
    {
        $items = array_map(fn (string $item) => mb_strtolower(trim(preg_replace('/\s+/u', ' ', $item))), $items);
        $items = array_values(array_unique(array_filter($items, fn ($item) => $item !== '')));
        sort($items, SORT_STRING);

        return $items;
    }
}
