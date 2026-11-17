<?php

use App\Http\Controllers\GenerationHistoryController;
use Illuminate\Support\Facades\Route;

// Routes for this slice are added by its tasks; see contracts/http.md.

Route::get('/generations', [GenerationHistoryController::class, 'index'])->name('generations.index');
