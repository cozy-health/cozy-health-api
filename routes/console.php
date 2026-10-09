<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

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
