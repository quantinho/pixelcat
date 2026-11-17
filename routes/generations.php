<?php

use App\Http\Controllers\EstimateController;
use App\Http\Controllers\GenerationController;
use Illuminate\Support\Facades\Route;

// Middleware `web` and `auth` come from bootstrap/app.php; see contracts/http.md.

Route::get('generate', [GenerationController::class, 'create'])->name('generations.create');
Route::post('generations/estimate', EstimateController::class)->name('generations.estimate');
Route::post('generations', [GenerationController::class, 'store'])->name('generations.store');
