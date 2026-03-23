<?php

use Illuminate\Support\Facades\Route;
use Npabisz\LaravelMetrics\Http\Controllers\MetricsController;
use Npabisz\LaravelMetrics\Http\Middleware\AuthorizeBearerToken;

Route::middleware(AuthorizeBearerToken::class)->group(function () {
    Route::get('summary', [MetricsController::class, 'summary']);
    Route::get('metrics', [MetricsController::class, 'metrics']);
    Route::get('slow-logs', [MetricsController::class, 'slowLogs']);
});
