<?php

namespace Tests\Unit;

use App\Support\RecipeCacheKey;
use Tests\TestCase;

class RecipeCacheKeyTest extends TestCase
{
    public function test_key_is_canonical_and_separates_items(): void
    {
        $keys = new RecipeCacheKey;
        $a = ['ingredients' => [' Telur ', 'NASI', 'telur'], 'servings' => '1', 'allergies' => 'Kacang, Udang'];
        $b = ['ingredients' => ['nasi', 'telur'], 'servings' => 1, 'preference' => 'bebas', 'allergies' => 'udang,kacang'];
        $this->assertSame($keys->make($a), $keys->make($b));
        $this->assertNotSame($keys->make(['ingredients' => ['a b', 'c']]), $keys->make(['ingredients' => ['a', 'b c']]));
    }

    public function test_preferences_allergies_servings_model_and_versions_change_key(): void
    {
        $keys = new RecipeCacheKey;
        $input = ['ingredients' => ['telur']];
        $original = $keys->make($input);
        foreach (['preference' => 'pedas', 'allergies' => 'kacang', 'servings' => 2] as $field => $value) {
            $this->assertNotSame($original, $keys->make($input + [$field => $value]));
        }
        foreach (['ai.gemini.model', 'ai.prompt_version', 'ai.schema_version'] as $setting) {
            $previous = config($setting);
            config([$setting => 'changed']);
            $this->assertNotSame($original, $keys->make($input));
            config([$setting => $previous]);
        }
    }
}
