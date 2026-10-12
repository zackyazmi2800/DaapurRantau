<?php

namespace Tests\Unit;

use App\Services\RecipeMatchingService;
use App\Support\RecipeMode;
use PHPUnit\Framework\TestCase;

class StrictRecipeMatchingTest extends TestCase
{
    public function test_strict_uses_controlled_aliases_and_optional_items_do_not_add_missing_required(): void
    {
        $service = new RecipeMatchingService;
        $recipe = ['ingredients' => [
            ['name' => 'cabai', 'quantity' => '1 buah', 'optional' => false],
            ['name' => 'garam', 'quantity' => 'sejumput', 'optional' => true],
        ]];
        $result = $service->rank([$recipe], ['ingredients' => [' Cabe '], 'mode' => RecipeMode::STRICT]);
        $this->assertCount(1, $result);
        $this->assertSame(0, $result[0]['match']['missing_ingredients_count']);
        $this->assertFalse($result[0]['match']['optional'][0]['available']);
        $this->assertSame('Tanpa Bahan Tambahan', $result[0]['match']['category']);
        $this->assertSame([], $service->strict([$recipe], ['ingredients' => ['cabai merah']]));
        $this->assertSame([], $service->strict([$recipe], ['ingredients' => ['cabai'], 'allergies' => 'garam']));
    }

    public function test_all_optional_or_combined_ingredients_cannot_claim_ready(): void
    {
        $service = new RecipeMatchingService;
        foreach ([['name' => 'telur', 'quantity' => '1', 'optional' => true], ['name' => 'telur dan air', 'quantity' => '1', 'optional' => false]] as $ingredient) {
            $this->assertSame([], $service->strict([['ingredients' => [$ingredient]]], ['ingredients' => ['telur', 'air', 'telur dan air']]));
        }
    }
}
