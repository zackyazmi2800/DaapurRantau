<?php

namespace Tests\Support;

use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;

trait GeminiFixture
{
    private function fakeProvider($responses): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake($responses);
    }

    private function recipeData(): array
    {
        $data = ['message' => 'Berikut ide masakan.', 'recipes' => [[
            'title' => 'Telur Rebus', 'description' => 'Masakan praktis.',
            'cook_time_minutes' => 12, 'servings' => 1, 'difficulty' => 'mudah',
            'ingredients' => [['name' => 'telur', 'quantity' => '1 butir', 'optional' => false],
                ['name' => 'air', 'quantity' => '500 ml', 'optional' => false]],
            'steps' => ['Rebus telur dalam air hingga matang.'], 'tips' => 'Tambahkan air untuk merebus.',
        ], [
            'title' => 'Telur Kukus', 'description' => 'Telur lembut dikukus.',
            'cook_time_minutes' => 15, 'servings' => 1, 'difficulty' => 'mudah',
            'ingredients' => [['name' => 'telur', 'quantity' => '1 butir', 'optional' => false],
                ['name' => 'air', 'quantity' => '500 ml', 'optional' => false],
                ['name' => 'garam', 'quantity' => 'sejumput', 'optional' => false]],
            'steps' => ['Kocok telur lalu kukus hingga matang.'], 'tips' => 'Siapkan air untuk kukusan.',
        ], [
            'title' => 'Telur Dadar Sayur', 'description' => 'Lauk sederhana.',
            'cook_time_minutes' => 10, 'servings' => 1, 'difficulty' => 'mudah',
            'ingredients' => [['name' => 'telur', 'quantity' => '1 butir', 'optional' => false],
                ['name' => 'minyak', 'quantity' => '1 sendok teh', 'optional' => false],
                ['name' => 'wortel', 'quantity' => '1 buah', 'optional' => false],
                ['name' => 'daun bawang', 'quantity' => '1 batang', 'optional' => false],
                ['name' => 'garam', 'quantity' => 'sejumput', 'optional' => false]],
            'steps' => ['Cincang wortel dan daun bawang, campur dengan telur dan garam, lalu goreng dengan minyak hingga matang.'], 'tips' => 'Siapkan minyak tambahan.',
        ]]];
        foreach ($data['recipes'] as &$recipe) {
            $instruction = $recipe['steps'][0];
            $recipe['steps'] = array_map(fn ($number) => [
                'step_number' => $number, 'title' => 'Tahap '.$number,
                'instruction' => $instruction, 'duration_minutes' => null,
                'cooking_tip' => 'Periksa kematangan sebelum disajikan.',
            ], range(1, 5));
        }

        return $data;
    }

    private function geminiBody(?array $data = null, string $finish = 'STOP'): array
    {
        return ['candidates' => [['finishReason' => $finish,
            'content' => ['parts' => [['text' => json_encode($data ?? $this->recipeData())]]]]],
            'usageMetadata' => ['promptTokenCount' => 100, 'candidatesTokenCount' => 200, 'totalTokenCount' => 300]];
    }
}
