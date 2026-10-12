<?php

namespace App\Services;

use Illuminate\Contracts\Session\Session;

class IngredientChecklistService
{
    public function __construct(private RecipeMatchingService $matching) {}

    public function state(Session $session, array $snapshot): array
    {
        $catalog = [];
        $initial = [];
        foreach ($snapshot['input']['ingredients'] as $name) {
            $normalized = $this->matching->normalize($name);
            $id = hash('sha256', $normalized);
            $catalog[$id] = $normalized;
            $initial[] = $id;
        }
        foreach ($snapshot['recipes'] as $recipe) {
            foreach ($recipe['ingredients'] as $ingredient) {
                $name = $this->matching->normalize($ingredient['name']);
                $catalog[hash('sha256', $name)] = $name;
            }
        }
        $saved = $session->get('ingredient_checklists.'.$snapshot['id'], $initial);
        $confirmed = array_values(array_intersect($saved, array_keys($catalog)));

        return ['catalog' => $catalog, 'confirmed' => $confirmed,
            'ingredients' => array_values(array_intersect_key($catalog, array_flip($confirmed)))];
    }

    public function save(Session $session, array $snapshot, array $confirmed): array
    {
        $state = $this->state($session, $snapshot);
        $confirmed = array_values(array_intersect($confirmed, array_keys($state['catalog'])));
        $all = $session->get('ingredient_checklists', []);
        $all[$snapshot['id']] = $confirmed;
        $session->put('ingredient_checklists', array_slice($all, -30, null, true));

        return $this->state($session, $snapshot);
    }

    public function forRecipe(Session $session, array $snapshot, array $recipe): array
    {
        $state = $this->state($session, $snapshot);
        $match = $this->matching->match($state['ingredients'], $recipe['ingredients'], explode(',', $snapshot['input']['allergies'] ?? ''));
        $items = [];
        foreach (array_merge($match['available'], $match['missing'], $match['optional']) as $item) {
            $id = hash('sha256', $this->matching->normalize($item['name']));
            $items[$id] = $item + ['key' => $id];
        }

        return $state + ['items' => $items, 'match' => $match,
            'other' => array_diff(array_unique(array_merge($state['confirmed'], array_map(fn ($name) => hash('sha256', $this->matching->normalize($name)), $snapshot['input']['ingredients']))), array_keys($items))];
    }
}
