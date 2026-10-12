<?php

namespace Tests\Unit;

use App\Exceptions\RecipeGenerationException;
use App\Services\RecipeMatchingService;
use PHPUnit\Framework\TestCase;

class RecipeCategoryTest extends TestCase
{
    private function recipes(array $counts): array
    {
        return array_map(function ($count) {
            $ingredients = [['name' => 'telur', 'quantity' => '1', 'optional' => false]];
            for ($i = 0; $i < $count; $i++) {
                $ingredients[] = ['name' => 'tambahan '.$i, 'quantity' => '1', 'optional' => false];
            }

            return ['title' => 'Menu '.$count, 'ingredients' => $ingredients];
        }, $counts);
    }

    public function test_category_boundaries_and_wrong_order_are_computed_by_backend(): void
    {
        foreach ([[0, 1, 4], [1, 3, 4], [2, 3, 5], [1, 1, 4], [2, 2, 4]] as $counts) {
            $ranked = (new RecipeMatchingService)->rank(array_reverse($this->recipes($counts)), ['ingredients' => ['telur']]);
            $this->assertSame($counts, array_column(array_column($ranked, 'match'), 'missing_ingredients_count'));
            $this->assertSame(['Paling Cocok', 'Cukup Cocok', 'Alternatif Menu'], array_column(array_column($ranked, 'match'), 'category'));
        }
    }

    public function test_all_missing_counts_are_allowed_and_sorted_without_category_quotas(): void
    {
        foreach ([[3, 3, 4], [0, 4, 5], [0, 1, 3], [0, 0, 0], [2, 4, 4], [2, 5, 8]] as $counts) {
            $ranked = (new RecipeMatchingService)->rank(array_reverse($this->recipes($counts)), ['ingredients' => ['telur']]);
            $this->assertSame($counts, array_column(array_column($ranked, 'match'), 'missing_ingredients_count'));
        }
    }

    public function test_duplicates_aliases_and_optional_rows_do_not_distort_counts(): void
    {
        $ingredients = [];
        foreach ([[' CABE ', false], ['cabai', false], ['garlic', true], [' BAWANG   PUTIH ', false], ['garam', false], ['garam', false], ['air', false], ['minyak', false], ['gula', true]] as [$name, $optional]) {
            $ingredients[] = compact('name', 'optional') + ['quantity' => '1'];
        }
        foreach ([$ingredients, array_reverse($ingredients)] as $items) {
            $match = (new RecipeMatchingService)->match(['cabai'], $items);
            $this->assertSame(4, $match['missing_ingredients_count']);
            $this->assertSame(5, $match['required']);
            $this->assertSame(1, $match['matched']);
            $this->assertCount(1, $match['optional']);
            $this->assertCount(1, $match['available']);
        }
    }

    public function test_one_ingredient_entry_cannot_combine_multiple_ingredients(): void
    {
        foreach (['garam dan minyak', 'garam, minyak', 'garam/minyak', 'garam & minyak', 'garam + minyak'] as $name) {
            $recipes = $this->recipes([0, 1, 4]);
            $recipes[1]['ingredients'][1]['name'] = $name;
            try {
                (new RecipeMatchingService)->rank($recipes, ['ingredients' => ['telur']]);
                $this->fail('Combined ingredient accepted.');
            } catch (RecipeGenerationException $exception) {
                $this->assertSame('invalid_ingredient_names', $exception->reason);
            }
        }
    }
}
