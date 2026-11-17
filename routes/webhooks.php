<?php

use App\Http\Controllers\PixelforgeWebhookController;
use Illuminate\Support\Facades\Route;

// No session or CSRF: Pixelforge authenticates by signature. Throttled per IP, generously
// (Pixelforge retries anything that isn't a 2xx).
Route::post('webhooks/pixelforge', PixelforgeWebhookController::class)
    ->middleware('throttle:600,1')
    ->name('webhooks.pixelforge');
