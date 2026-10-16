<?php

use App\Http\Controllers\FavoriteController;
use App\Http\Controllers\HistoryController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\RecommendationController;
use App\Http\Middleware\LimitConcurrentRecipes;
use Illuminate\Support\Facades\Route;

Route::get('/', [HomeController::class, 'index'])->name('home');

Route::get('/rekomendasi', fn () => redirect()->to(route('home').'#cari-resep'));

Route::post('/rekomendasi', [RecommendationController::class, 'store'])->middleware(['throttle:recipes', LimitConcurrentRecipes::class])->name('recommendations.store');

Route::get('/rekomendasi/{search}', [RecommendationController::class, 'show'])->name('recommendations.show');
Route::get('/rekomendasi/{search}/ubah', [RecommendationController::class, 'edit'])->name('recommendations.edit');
Route::get('/rekomendasi/{search}/resep/{recipe}', [RecommendationController::class, 'detail'])->name('recommendations.detail');

Route::patch('/rekomendasi/{search}/bahan', [RecommendationController::class, 'updateChecklist'])->middleware('throttle:60,1')->block(10, 10)->name('recommendations.checklist');
Route::post('/rekomendasi/{search}/alternatif', [RecommendationController::class, 'alternatives'])->middleware(['throttle:recipes', LimitConcurrentRecipes::class])->block(180, 10)->name('recommendations.alternatives');

Route::view('/akun-diperlukan', 'account.required')->name('account.required');

Route::middleware(\App\Http\Middleware\RequireCollectionAccount::class)->group(function () {
    Route::get('/favorit', [FavoriteController::class, 'index'])->name('favorites.index');
    Route::get('/favorit/{favorite}', [FavoriteController::class, 'show'])->whereNumber('favorite')->name('favorites.show');
    Route::post('/rekomendasi/{search}/resep/{recipe}/favorit', [FavoriteController::class, 'store'])->name('favorites.store');
    Route::delete('/favorit/{favorite}', [FavoriteController::class, 'destroy'])->whereNumber('favorite')->name('favorites.destroy');
    Route::get('/riwayat', [HistoryController::class, 'index'])->name('history.index');
    Route::delete('/riwayat', [HistoryController::class, 'clear'])->name('history.clear');
    Route::get('/riwayat/{history}/ulang', [HistoryController::class, 'repeat'])->whereNumber('history')->name('history.repeat');
    Route::delete('/riwayat/{history}', [HistoryController::class, 'destroy'])->whereNumber('history')->name('history.destroy');
});

// Authentication uses the existing web session/CSRF middleware and no guest ledger changes.
Route::middleware('guest')->group(function () {
    Route::get('/auth/google/redirect', [\App\Http\Controllers\GoogleLoginController::class, 'redirect'])->middleware('throttle:auth-google')->name('google.redirect');
    Route::get('/auth/google/callback', [\App\Http\Controllers\GoogleLoginController::class, 'callback'])->middleware('throttle:auth-google')->name('google.callback');
    Route::view('/masuk', 'auth.login')->name('login');
    Route::post('/masuk', [\App\Http\Controllers\AuthController::class, 'login'])->middleware('throttle:auth-login')->name('login.store');
    Route::view('/daftar', 'auth.register')->name('register');
    Route::post('/daftar', [\App\Http\Controllers\AuthController::class, 'register'])->middleware('throttle:auth-register')->name('register.store');
    Route::get('/verifikasi-email', [\App\Http\Controllers\AuthController::class, 'notice'])->name('verification.notice');
    Route::post('/verifikasi-email', [\App\Http\Controllers\AuthController::class, 'verify'])->middleware('throttle:auth-otp')->name('verification.verify');
    Route::post('/verifikasi-email/kirim-ulang', [\App\Http\Controllers\AuthController::class, 'resend'])->middleware('throttle:auth-resend')->name('verification.send');
    Route::view('/lupa-password', 'auth.forgot')->name('password.request');
    Route::post('/lupa-password', [\App\Http\Controllers\AuthController::class, 'forgot'])->middleware('throttle:auth-reset')->name('password.email');
    Route::get('/reset-password/{token}', [\App\Http\Controllers\AuthController::class, 'resetForm'])->name('password.reset');
    Route::post('/reset-password', [\App\Http\Controllers\AuthController::class, 'reset'])->middleware('throttle:auth-reset')->name('password.update');
});
Route::post('/keluar', [\App\Http\Controllers\AuthController::class, 'logout'])->middleware('auth')->name('logout');
