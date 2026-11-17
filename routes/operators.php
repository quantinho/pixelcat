<?php

use App\Http\Controllers\Admin\DeliveriesController;
use App\Http\Controllers\Admin\ReportController;
use Illuminate\Support\Facades\Route;

// Registered with the web, auth and can:admin middleware in bootstrap/app.php.
Route::get('admin/report', ReportController::class)->name('admin.report');
Route::get('admin/deliveries', DeliveriesController::class)->name('admin.deliveries');
