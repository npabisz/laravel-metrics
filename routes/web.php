<?php

use Illuminate\Support\Facades\Route;
use Npabisz\LaravelMetrics\Http\Controllers\DashboardController;
use Npabisz\LaravelMetrics\Http\Middleware\AuthorizeMetrics;

Route::middleware(AuthorizeMetrics::class)->group(function () {
    Route::get('/', [DashboardController::class, 'index']);
    Route::get('api/data', [DashboardController::class, 'apiData']);
    Route::get('api/slow-logs', [DashboardController::class, 'apiSlowLogs']);
});
