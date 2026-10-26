<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Schedule::call(function () {
    \Illuminate\Support\Facades\DB::table('quiz_invitations')->whereNull('accepted_at')->where('expires_at', '<', now()->subDays(7))->delete();
    \App\Models\QuizSession::where('expires_at', '<', now()->subDays(7))->whereNotIn('id', \App\Models\QuizAttempt::withTrashed()->select('id'))->delete();
})->name('quiz:cleanup')->daily()->withoutOverlapping();

Schedule::command('ops:heartbeat')
    ->name('ops:heartbeat')->everyMinute()->withoutOverlapping();

Schedule::call(fn () => app(\App\Services\Push\ReminderScheduler::class)->tick())->name('push:reminders')->everyMinute()->withoutOverlapping();

Artisan::command('push:tick', function () {
    app(\App\Services\Push\ReminderScheduler::class)->tick();
    $this->info('Reminder scheduling completed; sending remains subject to configuration and consent.');
});

Artisan::command('push:status', function () {
    $this->info('Push dispatch: '.(config('push.enabled') ? 'enabled' : 'disabled'));
    $this->info('iOS dispatch: '.(config('push.ios_enabled') ? 'enabled' : 'disabled'));
    foreach (['push_reminders', 'push_attempts'] as $table) {
        $counts = \Illuminate\Support\Facades\DB::table($table)->select('status')->selectRaw('count(*) as total')->groupBy('status')->orderBy('status')->get();
        $this->table([$table.' status', 'Count'], $counts->map(fn ($row) => [$row->status, $row->total])->all());
    }
});

Artisan::command('push:credentials-check', function () {
    if (! app(\App\Services\Push\FcmSender::class)->verifyCredentials()) {
        $this->error('FCM validation failed. Check the project, credential, API access and network. No notification was sent.');
        return 1;
    }
    $this->info('FCM HTTP v1 authorization and validation passed. No notification was sent.');
    return 0;
});

Schedule::command('analytics:prune --older-than=14months')->daily()->withoutOverlapping();

Artisan::command('settings:set {key} {value}', function () {
    $raw = $this->argument('value');
    $value = in_array($raw, ['true', 'false', 'null'], true) ? json_decode($raw, true) : $raw;
    if (in_array($this->argument('key'), ['consent.documents', 'consent.provider_documents'])) {
        try { $value = json_decode($raw, true, flags: JSON_THROW_ON_ERROR); }
        catch (\JsonException $e) { $this->error('Supply a valid JSON document object.'); return 1; }
    }
    try {
        app(\App\Services\SettingsService::class)->set($this->argument('key'), $value);
    } catch (\Illuminate\Validation\ValidationException $e) {
        $this->error(implode(' ', array_merge(...array_values($e->errors()))));
        return 1;
    }
    $this->info('Setting updated.');
    return 0;
});

Artisan::command('flags:set {key} {enabled}', function () {
    $key = $this->argument('key');
    $enabled = $this->argument('enabled');
    if (! array_key_exists($key, \App\Services\AppConfigService::DEFAULT_FLAGS) || ! in_array($enabled, ['true', 'false'], true)) {
        $this->error('Choose a registered flag and true or false. Crisis features are never flags.');
        return 1;
    }
    \App\Models\FeatureFlag::updateOrCreate(['key' => $key], ['enabled' => $enabled === 'true', 'description' => str_replace('_', ' ', $key)]);
    $this->info('Feature flag updated.');
    return 0;
});

\Illuminate\Support\Facades\Schedule::command('avatars:cleanup')->hourly()->withoutOverlapping()->runInBackground();

Artisan::command('audio:cleanup', function () { $this->info('Removed '.app(\App\Services\AudioAttachmentService::class)->cleanup().' unreferenced recordings.'); });
Schedule::command('audio:cleanup')->daily()->withoutOverlapping();

Schedule::command('launch:prune')->everyFiveMinutes()->withoutOverlapping();

// Retention is approved, but deployment must record the approval reference.
// The command also validates limits and holds before any destructive action.
Schedule::command('provider:prune --execute --orphan-reports')->daily()->withoutOverlapping()
    ->when(fn () => filled(config('provider-retention.approval_reference')));

Schedule::command('stores:reconcile')->everyFifteenMinutes()->withoutOverlapping();
