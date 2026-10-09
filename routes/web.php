<?php

use Illuminate\Support\Facades\Route;

Route::post('/webhooks/brevo', \App\Http\Controllers\BrevoWebhookController::class)
    ->withoutMiddleware('web')->middleware(['api', \App\Http\Middleware\VerifyBrevoToken::class, 'throttle:600,1']);

Route::get('/up', \App\Http\Controllers\HealthController::class)->withoutMiddleware('web');

Route::get('/admin/metrics/{metric}', [\App\Http\Controllers\AdminMetricsController::class, 'show'])
    ->where('metric', 'overview|usage|retention|safety')->withoutMiddleware('web')
    ->middleware(['api', 'auth:sanctum', 'role:admin', 'admin.analytics', 'throttle:30,1']);

Route::get('/admin/metrics/analytics', \App\Http\Controllers\AnalyticsMetricsController::class)
    ->withoutMiddleware('web')->middleware(['api', 'auth:sanctum', 'role:admin', 'admin.analytics', 'throttle:30,1']);

Route::put('/admin/settings/{key}', [\App\Http\Controllers\AdminSettingsController::class, 'update'])
    ->withoutMiddleware('web')->middleware(['api', 'auth:sanctum', 'role:admin', 'throttle:30,1']);
