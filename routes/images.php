<?php

use App\Http\Controllers\GenerationImageController;
use Illuminate\Support\Facades\Route;

// Registered with the web and auth middleware (bootstrap/app.php).
Route::get('generations/{generation}/image', GenerationImageController::class)
    ->whereUuid('generation')
    ->name('generations.image');
