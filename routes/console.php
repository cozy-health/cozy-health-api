<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Schedule::command('analytics:prune --older-than=14months')->daily()->withoutOverlapping();

Artisan::command('user:make-admin {email}', function () {
    $user = \App\Models\User::where('email', $this->argument('email'))->first();
    if (! $user) {
        $this->error('No active user found for that email.');
        return 1;
    }
    $user->forceFill(['role' => 'admin'])->save();
    $this->info('Admin role assigned.');
    return 0;
})->purpose('Grant an existing active user the administrator role');

Artisan::command('settings:set {key} {value}', function () {
    $raw = $this->argument('value');
    $value = in_array($raw, ['true', 'false', 'null'], true) ? json_decode($raw, true) : $raw;
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
