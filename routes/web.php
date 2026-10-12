<?php

use App\Http\Controllers\FavoriteController;
use App\Http\Controllers\HistoryController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\RecommendationController;
use App\Http\Middleware\LimitConcurrentRecipes;
use Illuminate\Support\Facades\Route;

Route::get('/', [HomeController::class, 'index'])->name('home');

Route::post('/rekomendasi', [RecommendationController::class, 'store'])->middleware(['throttle:recipes', LimitConcurrentRecipes::class])->name('recommendations.store');

Route::get('/rekomendasi/{search}', [RecommendationController::class, 'show'])->name('recommendations.show');
Route::get('/rekomendasi/{search}/ubah', [RecommendationController::class, 'edit'])->name('recommendations.edit');
Route::get('/rekomendasi/{search}/resep/{recipe}', [RecommendationController::class, 'detail'])->name('recommendations.detail');

Route::patch('/rekomendasi/{search}/bahan', [RecommendationController::class, 'updateChecklist'])->middleware('throttle:60,1')->block(10, 10)->name('recommendations.checklist');
Route::post('/rekomendasi/{search}/alternatif', [RecommendationController::class, 'alternatives'])->middleware(['throttle:recipes', LimitConcurrentRecipes::class])->block(180, 10)->name('recommendations.alternatives');

Route::get('/favorit', [FavoriteController::class, 'index'])->name('favorites.index');
Route::get('/favorit/{favorite}', [FavoriteController::class, 'show'])->whereNumber('favorite')->name('favorites.show');
Route::post('/rekomendasi/{search}/resep/{recipe}/favorit', [FavoriteController::class, 'store'])->name('favorites.store');
Route::delete('/favorit/{favorite}', [FavoriteController::class, 'destroy'])->whereNumber('favorite')->name('favorites.destroy');
Route::get('/riwayat', [HistoryController::class, 'index'])->name('history.index');
Route::delete('/riwayat', [HistoryController::class, 'clear'])->name('history.clear');
Route::get('/riwayat/{history}/ulang', [HistoryController::class, 'repeat'])->whereNumber('history')->name('history.repeat');
Route::delete('/riwayat/{history}', [HistoryController::class, 'destroy'])->whereNumber('history')->name('history.destroy');
