<?php

use App\Http\Controllers\EstimateController;
use App\Http\Controllers\GenerationController;
use Illuminate\Support\Facades\Route;

// Middleware `web` and `auth` come from bootstrap/app.php; see contracts/http.md.

Route::get('generate', [GenerationController::class, 'create'])->name('generations.create');
// The form asks while the user types (debounced); 60 a minute per user is plenty.
Route::post('generations/estimate', EstimateController::class)
    ->middleware('throttle:60,1')
    ->name('generations.estimate');
Route::post('generations', [GenerationController::class, 'store'])->name('generations.store');
