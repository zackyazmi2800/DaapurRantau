<?php

namespace App\Http\Controllers;

use App\Exceptions\RecipeGenerationException;
use App\Exceptions\RecommendationOperationException;
use App\Http\Requests\GenerateAvailableRecipeRequest;
use App\Http\Requests\GenerateRecipeRequest;
use App\Http\Requests\UpdateIngredientChecklistRequest;
use App\Services\GeminiRecipeService;
use App\Services\IngredientChecklistService;
use App\Services\RecipeMatchingService;
use App\Services\RecommendationOperationService;
use App\Services\RecommendationSnapshotService;
use App\Services\SavedRecipeService;
use App\Support\RecipeFailureResponse;
use App\Support\RecipeMode;
use App\Support\SqliteContention;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RecommendationController extends Controller
{
    public function __construct(private SavedRecipeService $favorites, private RecommendationOperationService $operations) {}

    public function store(GenerateRecipeRequest $request, GeminiRecipeService $service, RecipeMatchingService $matching, RecommendationSnapshotService $snapshots): Response
    {
        return $this->generate($request, $request->validated(), $service, $matching, $snapshots);
    }

    public function updateChecklist(UpdateIngredientChecklistRequest $request, IngredientChecklistService $checklists)
    {
        $state = $checklists->save($request->session(), $request->snapshot(), $request->validated('confirmed'));

        return response()->json(['confirmed' => $state['confirmed']]);
    }

    public function alternatives(GenerateAvailableRecipeRequest $request, IngredientChecklistService $checklists, GeminiRecipeService $service, RecipeMatchingService $matching, RecommendationSnapshotService $snapshots): Response
    {
        $snapshot = $request->snapshot();
        $state = $checklists->save($request->session(), $snapshot, $request->validated('confirmed'));
        $data = array_replace($snapshot['input'], ['ingredients' => $state['ingredients'], 'mode' => RecipeMode::STRICT]);

        return $this->generate($request, $data, $service, $matching, $snapshots);
    }

    private function generate(Request $request, array $data, GeminiRecipeService $service, RecipeMatchingService $matching, RecommendationSnapshotService $snapshots): Response
    {
        $operations = $this->operations;
        $lease = null;
        $committed = false;
        try {
            $lease = $operations->reserve($request, $data);
            if ($lease->status === 'succeeded') {
                return redirect()->route('recommendations.show', $lease->result_id, 303);
            }
            $result = $service->generateRecipes($data);
            $ranked = $matching->rank($result['recipes'], $data);
            if (! $ranked || (! RecipeMode::strict($data) && (count($result['recipes']) !== 3 || count($ranked) !== 3))) {
                throw new RecipeGenerationException('invalid_recipe_count');
            }
            $snapshot = $snapshots->prepare($data, $ranked, $result['message']);
            $operations->complete($lease, $snapshot);
            $committed = true;

            return redirect()->route('recommendations.show', $snapshot['id'], 303);
        } catch (RecommendationOperationException $exception) {
            $request->merge($data);
            $request->session()->flashInput($request->only('ingredients', 'servings', 'preference', 'allergies', 'mode', 'idempotency_key'));
            $message = $exception->getMessage();

            return $request->expectsJson()
                ? response()->json(['message' => $message, 'account_url' => route('account.required')], $exception->status)
                : response()->view('recommendations.operation-error', compact('message'), $exception->status);
        } catch (RecipeGenerationException $exception) {
            $request->merge($data);

            return RecipeFailureResponse::make($request, $exception->getMessage(), $exception->status);
        } catch (\Throwable $exception) {
            if (SqliteContention::isBusy($exception)) {
                return SqliteContention::response($request);
            }
            // Do not expose provider bodies, SQL bindings or identity tokens.
            return RecipeFailureResponse::make($request, RecipeGenerationException::UNAVAILABLE, 503);
        } finally {
            if ($lease && $lease->status === 'reserved' && ! $committed) {
                try {
                    $operations->release($lease);
                } catch (\Throwable) {
                    // A crashed/unavailable DB is recovered by lease expiry, never by charging.
                    \Illuminate\Support\Facades\Log::warning('recommendation.operation', ['status' => 'release_deferred']);
                }
            }
        }
    }

    public function show(Request $request, string $search, RecommendationSnapshotService $snapshots): Response
    {
        $snapshot = $snapshots->find($request->session(), $search);

        if ($snapshot && $request->user()) {
            $snapshot['recipes'] = $this->favorites->mark($request->user()->getAuthIdentifier(), $snapshot['recipes']);
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
        if ($request->user()) {
            $snapshot['recipes'] = $this->favorites->mark($request->user()->getAuthIdentifier(), $snapshot['recipes']);
        }
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
        $request->session()->flashInput(array_replace($snapshot['input'], ['mode' => RecipeMode::NORMAL]));

        return redirect()->to(route('home').'#cari-resep');
    }
}
