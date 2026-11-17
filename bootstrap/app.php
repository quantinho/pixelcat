<?php

use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            // One route file per slice; middleware per contracts/http.md.
            Route::middleware(['web', 'auth'])->group(base_path('routes/generations.php'));
            Route::middleware(['web', 'auth'])->group(base_path('routes/images.php'));
            Route::middleware(['web', 'auth'])->group(base_path('routes/credits.php'));
            Route::middleware(['web', 'auth', 'can:admin'])->group(base_path('routes/operators.php'));

            // Pixelforge has no session or CSRF token; requests are authenticated by signature.
            Route::group([], base_path('routes/webhooks.php'));

            if (config('pixelforge.fake') && ! app()->isProduction()) {
                Route::prefix('_fake/pixelforge/v1')->group(base_path('routes/fake-pixelforge.php'));
            }
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
