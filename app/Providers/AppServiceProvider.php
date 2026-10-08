<?php

namespace App\Providers;

use App\Services\SlowQueryLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        \Illuminate\Support\Facades\RateLimiter::for('password-reset-email', fn ($request) =>
            \Illuminate\Cache\RateLimiting\Limit::perHour(3)->by(hash_hmac('sha256', strtolower((string) $request->input('email')), config('app.key'))));
        \Illuminate\Support\Facades\RateLimiter::for('verification-email', fn ($request) =>
            \Illuminate\Cache\RateLimiting\Limit::perHour(3)->by((string) $request->user()->id));
        DB::listen(fn ($query) => app(SlowQueryLogger::class)($query));
    }
}
