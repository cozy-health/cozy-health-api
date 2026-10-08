<?php

use Illuminate\Support\Facades\Route;

Route::post('/webhooks/brevo', \App\Http\Controllers\BrevoWebhookController::class)
    ->withoutMiddleware('web')->middleware(['api', \App\Http\Middleware\VerifyBrevoToken::class, 'throttle:600,1']);

Route::get('/up', \App\Http\Controllers\HealthController::class)->withoutMiddleware('web');

Route::put('/admin/settings/{key}', [\App\Http\Controllers\AdminSettingsController::class, 'update'])
    ->withoutMiddleware('web')->middleware(['api', 'auth:sanctum', 'role:admin', 'throttle:30,1']);
