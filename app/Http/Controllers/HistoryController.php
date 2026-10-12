<?php

namespace App\Http\Controllers;

use App\Http\Requests\ClearHistoryRequest;
use App\Models\RecipeSearch;
use App\Services\RecommendationSnapshotService;
use App\Services\VisitorIdentity;
use Illuminate\Http\Request;

class HistoryController extends Controller
{
    public function index(Request $request, VisitorIdentity $identity, RecommendationSnapshotService $snapshots)
    {
        $searches = RecipeSearch::where('visitor_id', $identity->id($request->session()))->latest('id')->get();
        $available = [];
        foreach ($searches as $search) {
            $available[$search->id] = $snapshots->find($request->session(), $search->snapshot_id) !== null;
        }

        return view('history.index', compact('searches', 'available'));
    }

    public function repeat(Request $request, string $history, VisitorIdentity $identity)
    {
        $search = RecipeSearch::where('visitor_id', $identity->id($request->session()))->findOrFail($history);
        $request->session()->flashInput(['ingredients' => $search->ingredients] + $search->preferences + ['allergies' => null]);

        return redirect()->to(route('home').'#cari-resep');
    }

    public function destroy(Request $request, string $history, VisitorIdentity $identity)
    {
        RecipeSearch::where('visitor_id', $identity->id($request->session()))->findOrFail($history)->delete();

        return redirect()->route('history.index')->with('status', 'Riwayat pencarian dihapus.');
    }

    public function clear(ClearHistoryRequest $request, VisitorIdentity $identity)
    {
        RecipeSearch::where('visitor_id', $identity->id($request->session()))->delete();

        return redirect()->route('history.index')->with('status', 'Seluruh riwayatmu berhasil dihapus.');
    }
}
