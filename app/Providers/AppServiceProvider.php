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
        \App\Services\DevelopmentMailSafety::register();
        \App\Services\EmailDeliveryTracking::register();
        \Illuminate\Support\Facades\RateLimiter::for('provider-invitation-code', fn ($request) =>
            \Illuminate\Cache\RateLimiting\Limit::perMinute(10)->by('provider-invitation:'.$request->user()->id));
        \Illuminate\Support\Facades\RateLimiter::for('admin-email-test', fn ($request) =>
            \Illuminate\Cache\RateLimiting\Limit::perHour(3)->by('admin-email-test:'.$request->user()->id));
        \App\Models\SubscriptionStatus::updated(function ($subscription) {
            if (!\Illuminate\Support\Facades\Schema::hasTable('subscription_events')) return;
            if ($subscription->wasChanged(['status', 'plan', 'expires_at', 'cancelled_at'])) DB::table('subscription_events')->insert(['user_id' => $subscription->user_id,
                'subscription_id' => $subscription->id, 'plan' => $subscription->plan, 'status' => $subscription->status, 'source' => 'subscription-status', 'created_at' => now()]);
        });
        \App\Models\SubscriptionStatus::created(function ($subscription) {
            if (!\Illuminate\Support\Facades\Schema::hasTable('subscription_events')) return;
            $subscription = $subscription->fresh();
            DB::table('subscription_events')->insert(['user_id' => $subscription->user_id, 'subscription_id' => $subscription->id,
                'plan' => $subscription->plan, 'status' => $subscription->status, 'source' => 'subscription-created', 'created_at' => now()]);
        });
        \Illuminate\Support\Facades\RateLimiter::for('password-reset-email', fn ($request) =>
            \Illuminate\Cache\RateLimiting\Limit::perMinutes(15, 5)->by('reset-v2:'.hash_hmac('sha256', strtolower(trim((string) $request->input('email'))), config('app.key'))));
        \Illuminate\Support\Facades\RateLimiter::for('verification-email', fn ($request) =>
            \Illuminate\Cache\RateLimiting\Limit::perHour(3)->by((string) $request->user()->id));
        \Illuminate\Support\Facades\RateLimiter::for('provider-password-reset-email', fn ($request) =>
            \Illuminate\Cache\RateLimiting\Limit::perMinutes(15, 5)->by('reset-v2:'.hash_hmac('sha256', 'provider:'.strtolower(trim((string) $request->input('email'))), config('app.key'))));
        DB::listen(fn ($query) => app(SlowQueryLogger::class)($query));
    }
}
