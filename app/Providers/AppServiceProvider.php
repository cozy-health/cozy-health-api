<?php

namespace App\Providers;

use App\Services\SlowQueryLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(\App\Services\Push\PushSender::class, \App\Services\Push\FcmSender::class);
        $this->app->bind('db.connector.mysql', \App\Database\TlsMySqlConnector::class);
    }

    public function boot(): void
    {
        \Illuminate\Support\Facades\RateLimiter::for('password-reset-email', fn ($request) =>
            \Illuminate\Cache\RateLimiting\Limit::perMinutes(15, 5)->by('reset-v2:'.hash_hmac('sha256', strtolower(trim((string) $request->input('email'))), config('app.key'))));
        \Illuminate\Support\Facades\RateLimiter::for('verification-email', fn ($request) =>
            \Illuminate\Cache\RateLimiting\Limit::perHour(3)->by((string) $request->user()->id));
        \Illuminate\Support\Facades\RateLimiter::for('provider-password-reset-email', fn ($request) =>
            \Illuminate\Cache\RateLimiting\Limit::perMinutes(15, 5)->by('reset-v2:'.hash_hmac('sha256', 'provider:'.strtolower(trim((string) $request->input('email'))), config('app.key'))));
        DB::listen(fn ($query) => app(SlowQueryLogger::class)($query));
    }
}
