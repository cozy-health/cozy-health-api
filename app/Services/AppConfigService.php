<?php
namespace App\Services;

use App\Models\FeatureFlag;
use Illuminate\Support\Facades\Cache;

class AppConfigService
{
    public const DEFAULT_FLAGS = [
        'ai_assistant' => false, 'push_notifications' => true, 'community' => false,
        'content_feed' => false, 'quiz' => true, 'widgets' => false, 'analytics' => false,
    ];

    public function get(): array
    {
        $config = Cache::remember('app:config', 60, fn () => [
            'flags' => array_replace(self::DEFAULT_FLAGS, FeatureFlag::pluck('enabled', 'key')->all()),
            ...array_replace(\App\Models\Setting::DEFAULTS, \App\Models\Setting::pluck('value', 'key')->all()),
        ]);
        $config['flags']['ai_assistant'] = false; // Excluded from this release, including persisted flags.
        $config['flags']['plan_access'] = (bool) config('plan_access.enabled');
        $config['flags']['community'] = app(CommunityAvailability::class)->effective((bool) $config['flags']['community']);
        unset($config['consent.enforce_blocking'], $config['consent.documents'], $config['consent.provider_documents']);
        return [...$config, 'age' => ['enforced' => false, 'minimum_age' => 13, 'managed_by' => 'store_ratings'], 'consent' => app(DocumentConsentService::class)->configuration(), 'server_time' => now()->toIso8601String()];
    }
}
