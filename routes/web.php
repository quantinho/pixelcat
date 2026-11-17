<?php

use App\Billing\Money;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', fn () => Inertia::render('Welcome', [
    'welcomeCredit' => Money::displayCents((int) config('pixelcat.welcome_credit')),
    'markupPercent' => (int) config('pixelcat.markup_percent'),
]))->name('home');

Route::middleware('auth')->group(function () {
    Route::redirect('dashboard', '/generate')->name('dashboard');
});

require __DIR__.'/settings.php';
