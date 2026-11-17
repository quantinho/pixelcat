<?php

use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware('auth')->group(function () {
    Route::redirect('dashboard', '/generate')->name('dashboard');
});

require __DIR__.'/settings.php';
