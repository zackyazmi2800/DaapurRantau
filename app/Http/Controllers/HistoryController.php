<?php

namespace App\Http\Controllers;

use App\Http\Requests\ClearHistoryRequest;
use App\Models\RecipeSearch;
use App\Services\RecommendationSnapshotService;
use App\Support\RecipeMode;
use Illuminate\Http\Request;

class HistoryController extends Controller
{
    public function index(Request $request, RecommendationSnapshotService $snapshots)
    {
        $searches = RecipeSearch::where('user_id', $request->user()->getAuthIdentifier())->latest('id')->get();
        $available = [];
        foreach ($searches as $search) {
            $available[$search->id] = $snapshots->find($request->session(), $search->snapshot_id) !== null;
        }

        return view('history.index', compact('searches', 'available'));
    }

    public function repeat(Request $request, string $history)
    {
        $search = RecipeSearch::where('user_id', $request->user()->getAuthIdentifier())->findOrFail($history);
        $request->session()->flashInput(array_replace(['ingredients' => $search->ingredients] + $search->preferences + ['allergies' => null], ['mode' => RecipeMode::NORMAL]));

        return redirect()->to(route('home').'#cari-resep');
    }

    public function destroy(Request $request, string $history)
    {
        RecipeSearch::where('user_id', $request->user()->getAuthIdentifier())->findOrFail($history)->delete();

        return redirect()->route('history.index')->with('status', 'Riwayat pencarian dihapus.');
    }

    public function clear(ClearHistoryRequest $request)
    {
        RecipeSearch::where('user_id', $request->user()->getAuthIdentifier())->delete();

        return redirect()->route('history.index')->with('status', 'Seluruh riwayatmu berhasil dihapus.');
    }
}
