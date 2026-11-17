<?php

use App\Http\Controllers\CreditsController;
use Illuminate\Support\Facades\Route;

// Registered with the web and auth middleware in bootstrap/app.php.
Route::get('credits', [CreditsController::class, 'index'])->name('credits.index');
Route::post('credits/purchase', [CreditsController::class, 'purchase'])
    ->middleware('throttle:20,1')
    ->name('credits.purchase');
