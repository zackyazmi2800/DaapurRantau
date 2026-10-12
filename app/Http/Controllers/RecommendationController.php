<?php

namespace App\Http\Controllers;

use App\Exceptions\RecipeGenerationException;
use App\Http\Requests\GenerateAvailableRecipeRequest;
use App\Http\Requests\GenerateRecipeRequest;
use App\Http\Requests\UpdateIngredientChecklistRequest;
use App\Services\GeminiRecipeService;
use App\Services\IngredientChecklistService;
use App\Services\RecipeImageService;
use App\Services\RecipeMatchingService;
use App\Services\RecommendationSnapshotService;
use App\Services\SavedRecipeService;
use App\Services\SearchHistoryService;
use App\Services\VisitorIdentity;
use App\Support\RecipeFailureResponse;
use App\Support\RecipeMode;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RecommendationController extends Controller
{
    public function __construct(private SavedRecipeService $favorites, private VisitorIdentity $identity, private SearchHistoryService $history) {}

    public function store(GenerateRecipeRequest $request, GeminiRecipeService $service, RecipeMatchingService $matching, RecommendationSnapshotService $snapshots, RecipeImageService $images): Response
    {
        return $this->generate($request, $request->validated(), $service, $matching, $snapshots, $images);
    }

    public function updateChecklist(UpdateIngredientChecklistRequest $request, IngredientChecklistService $checklists)
    {
        $state = $checklists->save($request->session(), $request->snapshot(), $request->validated('confirmed'));

        return response()->json(['confirmed' => $state['confirmed']]);
    }

    public function alternatives(GenerateAvailableRecipeRequest $request, IngredientChecklistService $checklists, GeminiRecipeService $service, RecipeMatchingService $matching, RecommendationSnapshotService $snapshots, RecipeImageService $images): Response
    {
        $snapshot = $request->snapshot();
        $state = $checklists->save($request->session(), $snapshot, $request->validated('confirmed'));
        $data = array_replace($snapshot['input'], ['ingredients' => $state['ingredients'], 'mode' => RecipeMode::STRICT]);

        return $this->generate($request, $data, $service, $matching, $snapshots, $images);
    }

    private function generate(Request $request, array $data, GeminiRecipeService $service, RecipeMatchingService $matching, RecommendationSnapshotService $snapshots, RecipeImageService $images): Response
    {
        try {
            $result = $service->generateRecipes($data);
            $ranked = $matching->rank($result['recipes'], $data);
            if (! RecipeMode::strict($data) && (count($result['recipes']) !== 3 || count($ranked) !== 3)) {
                throw new RecipeGenerationException('invalid_recipe_count');
            }
        } catch (RecipeGenerationException $exception) {
            $request->merge($data);

            return RecipeFailureResponse::make($request, $exception->getMessage(), $exception->status);
        }

        $recipeImages = $images->getImagesForRecipes($ranked);
        foreach ($ranked as $index => &$recipe) {
            $recipe['image'] = $recipeImages[$index];
        }
        unset($recipe);
        $snapshot = $snapshots->create($request->session(), $data, $ranked, $result['message']);
        $this->history->record($this->identity->id($request->session()), $snapshot);
        if ($request->expectsJson()) {
            $result['recipes'] = array_map(function ($recipe) {
                unset($recipe['match']);

                return $recipe;
            }, $ranked);

            return response()->json($result);
        }
        $snapshot['recipes'] = $this->favorites->mark($this->identity->id($request->session()), $snapshot['recipes']);

        return response()->view('recommendations.preview', compact('data', 'snapshot'));
    }

    public function show(Request $request, string $search, RecommendationSnapshotService $snapshots): Response
    {
        $snapshot = $snapshots->find($request->session(), $search);

        if ($snapshot) {
            $snapshot['recipes'] = $this->favorites->mark($this->identity->id($request->session()), $snapshot['recipes']);
        }

        return $snapshot
            ? response()->view('recommendations.preview', ['snapshot' => $snapshot, 'data' => $snapshot['input']])
            : response()->view('recommendations.expired', [], 410);
    }

    public function detail(Request $request, string $search, string $recipe, RecommendationSnapshotService $snapshots, IngredientChecklistService $checklists): Response
    {
        $snapshot = $snapshots->find($request->session(), $search);
        if (! $snapshot) {
            return response()->view('recommendations.expired', [], 410);
        }
        $snapshot['recipes'] = $this->favorites->mark($this->identity->id($request->session()), $snapshot['recipes']);
        $selected = collect($snapshot['recipes'])->firstWhere('id', $recipe);

        return $selected
            ? response()->view('recommendations.detail', ['snapshot' => $snapshot, 'recipe' => $selected, 'checklist' => $checklists->forRecipe($request->session(), $snapshot, $selected)])
            : response()->view('recommendations.expired', [], 404);
    }

    public function edit(Request $request, string $search, RecommendationSnapshotService $snapshots): Response
    {
        $snapshot = $snapshots->find($request->session(), $search);
        if (! $snapshot) {
            return response()->view('recommendations.expired', [], 410);
        }
        $request->session()->flashInput($snapshot['input']);

        return redirect()->to(route('home').'#cari-resep');
    }
}
