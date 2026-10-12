<?php

namespace App\Http\Controllers;

use App\Models\SavedRecipe;
use App\Services\RecipeMatchingService;
use App\Services\RecommendationSnapshotService;
use App\Services\SavedRecipeService;
use App\Services\VisitorIdentity;
use Illuminate\Http\Request;

class FavoriteController extends Controller
{
    public function index(Request $request, VisitorIdentity $identity)
    {
        return view('favorites.index', ['favorites' => SavedRecipe::where('visitor_id', $identity->id($request->session()))->latest('id')->paginate(12)]);
    }

    public function store(Request $request, string $search, string $recipe, RecommendationSnapshotService $snapshots, VisitorIdentity $identity, SavedRecipeService $favorites)
    {
        $snapshot = $snapshots->find($request->session(), $search);
        if (! $snapshot) {
            return response()->view('recommendations.expired', [], 410);
        }
        $selected = collect($snapshot['recipes'])->firstWhere('id', $recipe);
        abort_unless($selected, 404);
        // Browser supplies identifiers only. Never read recipe JSON from the request.
        $favorites->save($identity->id($request->session()), $selected);

        return redirect()->route('recommendations.detail', [$search, $recipe])->with('status', 'Resep berhasil disimpan ke favorit.');
    }

    public function show(Request $request, string $favorite, VisitorIdentity $identity, RecipeMatchingService $matching)
    {
        $saved = SavedRecipe::where('visitor_id', $identity->id($request->session()))->findOrFail($favorite);
        $recipe = $saved->recipe_data;
        $recipe['match'] = $matching->match([], $recipe['ingredients']);
        $recipe['favorite_id'] = $saved->id;

        return view('recommendations.detail', ['recipe' => $recipe, 'saved' => true]);
    }

    public function destroy(Request $request, string $favorite, VisitorIdentity $identity)
    {
        $saved = SavedRecipe::where('visitor_id', $identity->id($request->session()))->findOrFail($favorite);
        $saved->delete();

        return redirect()->route('favorites.index')->with('status', 'Resep dihapus dari favorit.');
    }
}
