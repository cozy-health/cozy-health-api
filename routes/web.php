<?php

use Illuminate\Support\Facades\Route;

Route::get('/quiz-invite/{token}', fn () => response()->view('quiz.invitation')->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer')->header('X-Robots-Tag', 'noindex, nofollow'))
    ->where('token', '[a-f0-9]{64}')->middleware('throttle:60,1');
Route::get('/.well-known/assetlinks.json', function () {
    abort_unless(config('quiz.android_fingerprints'), 503, 'Configure release signing fingerprints.');
    return response()->json([['relation' => ['delegate_permission/common.handle_all_urls'], 'target' =>
        ['namespace' => 'android_app', 'package_name' => config('quiz.android_package'), 'sha256_cert_fingerprints' => config('quiz.android_fingerprints')]]]);
});
Route::get('/.well-known/apple-app-site-association', function () {
    abort_unless(config('quiz.ios_app_id'), 503, 'Configure the release application identifier.');
    return response()->json(['applinks' => ['apps' => [], 'details' => [['appID' => config('quiz.ios_app_id'), 'paths' => ['/quiz-invite/*']]]]]);
});

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
