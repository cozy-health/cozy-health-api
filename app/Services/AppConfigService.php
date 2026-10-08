<?php
namespace App\Services;

use App\Models\FeatureFlag;
use Illuminate\Support\Facades\Cache;

class AppConfigService
{
    public const DEFAULT_FLAGS = [
        'ai_assistant' => true, 'push_notifications' => true, 'community' => false,
        'content_feed' => false, 'quiz' => true, 'widgets' => false, 'analytics' => false,
    ];

    public function get(): array
    {
        $config = Cache::remember('app:config', 60, fn () => [
            'flags' => array_replace(self::DEFAULT_FLAGS, FeatureFlag::pluck('enabled', 'key')->all()),
            ...array_replace(\App\Models\Setting::DEFAULTS, \App\Models\Setting::pluck('value', 'key')->all()),
        ]);
        return [...$config, 'server_time' => now()->toIso8601String()];
    }
}
