<?php

\Illuminate\Support\Facades\Route::get('/support-inbox', fn () => response('<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Cozy Health support</title><main><h1>Your Cozy Health support inbox</h1><p>Open the app and sign in to read your private conversations.</p><p><a href="cozyhealth://support-inbox">Open Cozy Health</a></p><p>If the app does not open, go to Help &amp; Support, then My support conversations in your installed app.</p><p>This page contains no conversation details. Support is not an emergency service.</p></main></html>')->header('Content-Type', 'text/html; charset=utf-8')->header('Cache-Control', 'no-store')->header('Content-Security-Policy', "default-src 'none'; base-uri 'none'; frame-ancestors 'none'"))->withoutMiddleware('web')->middleware('api');

use Illuminate\Support\Facades\Route;

Route::get('/quiz-invite/{token}', fn () => response()->view('quiz.invitation')->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer')->header('X-Robots-Tag', 'noindex, nofollow'))
    ->where('token', '[a-f0-9]{64}')->middleware('throttle:60,1');
Route::get('/.well-known/assetlinks.json', function () {
    abort_unless(config('quiz.android_fingerprints'), 503, 'Configure release signing fingerprints.');
    return response()->json([['relation' => ['delegate_permission/common.handle_all_urls'], 'target' =>
        ['namespace' => 'android_app', 'package_name' => config('quiz.android_package'), 'sha256_cert_fingerprints' => config('quiz.android_fingerprints')]]]);
})->withoutMiddleware('web')->middleware('api');
Route::get('/.well-known/apple-app-site-association', function () {
    abort_unless(config('quiz.ios_app_id'), 503, 'Configure the release application identifier.');
    return response()->json(['applinks' => ['apps' => [], 'details' => [['appID' => config('quiz.ios_app_id'), 'paths' => ['/quiz-invite/*']]]]]);
})->withoutMiddleware('web')->middleware('api');

Route::post('/webhooks/brevo', \App\Http\Controllers\BrevoWebhookController::class)
    ->withoutMiddleware('web')->middleware(['api', \App\Http\Middleware\VerifyBrevoToken::class, 'throttle:600,1']);

Route::get('/up', \App\Http\Controllers\HealthController::class)->withoutMiddleware('web');

Route::get('/admin/health/queue-depth', \App\Http\Controllers\AdminQueueHealthController::class)
    ->withoutMiddleware('web')->middleware(['api', 'auth:sanctum', 'role:admin', 'throttle:30,1']);

Route::get('/admin/metrics/{metric}', [\App\Http\Controllers\AdminMetricsController::class, 'show'])
    ->where('metric', 'overview|usage|retention|safety')->withoutMiddleware('web')
    ->middleware(['api', 'auth:sanctum', 'role:admin', 'admin.analytics', 'throttle:30,1']);

Route::get('/admin/metrics/analytics', \App\Http\Controllers\AnalyticsMetricsController::class)
    ->withoutMiddleware('web')->middleware(['api', 'auth:sanctum', 'role:admin', 'admin.analytics', 'throttle:30,1']);

Route::put('/admin/settings/{key}', [\App\Http\Controllers\AdminSettingsController::class, 'update'])
    ->withoutMiddleware('web')->middleware(['api', 'auth:sanctum', 'role:admin', 'throttle:30,1']);
