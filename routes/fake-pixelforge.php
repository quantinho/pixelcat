<?php

use App\Dev\FakePixelforge\FakePixelforgeController;
use Illuminate\Support\Facades\Route;

// DEV ONLY: registered under /_fake/pixelforge/v1 when PIXELFORGE_FAKE=true outside production
// (bootstrap/app.php). Point PIXELFORGE_BASE_URL at {APP_URL}/_fake/pixelforge/v1 to use it.
Route::get('pricing', [FakePixelforgeController::class, 'pricing']);
Route::post('generations', [FakePixelforgeController::class, 'create']);
Route::get('generations', [FakePixelforgeController::class, 'index']);
Route::get('generations/{id}', [FakePixelforgeController::class, 'show']);
Route::get('usage', [FakePixelforgeController::class, 'usage']);
Route::get('images/{id}.png', [FakePixelforgeController::class, 'image']);
