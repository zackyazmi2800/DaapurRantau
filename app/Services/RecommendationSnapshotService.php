<?php

namespace App\Services;

use App\Exceptions\RecipeGenerationException;
use App\Support\RecipeMode;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class RecommendationSnapshotService
{
    public function __construct(private RecipeMatchingService $matching) {}

    public function create(Session $session, array $input, array $recipes, string $message): array
    {
        if (! $session->has('recommendation_owner')) {
            $session->put('recommendation_owner', Str::random(64));
        }
        $id = (string) Str::uuid();
        $snapshot = ['matching_version' => '2', 'id' => $id, 'owner' => hash('sha256', $session->get('recommendation_owner')),
            'input' => $input, 'message' => $message, 'recipes' => [], 'expires_at' => now()->addHour()->timestamp];
        foreach ($recipes as $recipe) {
            $snapshot['recipes'][] = ['id' => (string) Str::uuid()] + $recipe;
        }
        Cache::put('recommendation-snapshot:'.$id, $snapshot, 3600);

        return $snapshot;
    }

    public function find(Session $session, string $id): ?array
    {
        $owner = $session->get('recommendation_owner');
        if (! is_string($owner) || ! Str::isUuid($id)) {
            return null;
        }
        $snapshot = Cache::get('recommendation-snapshot:'.$id);
        if (! is_array($snapshot) || ($snapshot['matching_version'] ?? null) !== '2' || ! is_array($snapshot['recipes'] ?? null) || (RecipeMode::strict($snapshot['input'] ?? []) ? count($snapshot['recipes']) > 3 : count($snapshot['recipes']) !== 3)
            || ($snapshot['expires_at'] ?? 0) <= now()->timestamp
            || ! hash_equals($snapshot['owner'], hash('sha256', $owner))) {
            return null;
        }

        // Recheck old normal snapshots without issuing any provider request.
        if (! RecipeMode::strict($snapshot['input'])) {
            try {
                $snapshot['recipes'] = $this->matching->rank($snapshot['recipes'], $snapshot['input']);
            } catch (RecipeGenerationException) {
                return null;
            }
        }

        return $snapshot;
    }
}
