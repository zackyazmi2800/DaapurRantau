<?php

namespace Tests\Unit;

use App\Services\RecipeMatchingService;
use PHPUnit\Framework\TestCase;

class RecipeMatchingTest extends TestCase
{
    public function test_aliases_whitespace_and_optional_ingredients(): void
    {
        $match = (new RecipeMatchingService)->match([' CABE  MERAH ', 'garlic'], [
            ['name' => 'cabai merah', 'quantity' => '1', 'optional' => false],
            ['name' => 'bawang putih', 'quantity' => '1', 'optional' => false],
            ['name' => 'garam', 'quantity' => '1', 'optional' => true],
        ]);
        $this->assertTrue($match['ready']);
        $this->assertSame(2, $match['matched']);
        $this->assertCount(1, $match['optional']);
        $this->assertFalse($match['ingredients'][2]['available']);
    }

    public function test_no_substring_matches_or_implicit_pantry_items(): void
    {
        $match = (new RecipeMatchingService)->match(['bawang', 'minyak kelapa'], [
            ['name' => 'bawang putih', 'quantity' => '1', 'optional' => false],
            ['name' => 'minyak', 'quantity' => '1', 'optional' => false],
            ['name' => 'garam', 'quantity' => '1', 'optional' => false],
        ]);
        $this->assertFalse($match['ready']);
        $this->assertCount(3, $match['missing']);
    }

    public function test_avoidance_aliases_and_qualified_names_include_optional_items(): void
    {
        $service = new RecipeMatchingService;
        foreach ([['garlic', 'bawang putih'], ['udang kupas', 'udang'], ['cabe merah', 'cabai']] as [$ingredient, $allergen]) {
            $match = $service->match([$ingredient], [['name' => $ingredient, 'quantity' => '1', 'optional' => true]], [$allergen]);
            $this->assertTrue($match['blocked']);
            $this->assertFalse($match['ready']);
        }
    }
}
