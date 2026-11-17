<?php

namespace App\Services;

use App\Models\FeatureFlag;

class CommunityAvailability
{
    public const DEVELOPMENT_PROJECT = '33f9a938-49e3-42c5-83c7-633ee85760fa';
    public const DEVELOPMENT_ENVIRONMENT = '56b85cc3-e80b-4cb9-a0d4-b47abe8586f6';

    public function developmentTesting(): bool
    {
        $url = parse_url((string) config('app.url'));
        return config('community.development_testing') === true
            && config('community.railway_project_id') === self::DEVELOPMENT_PROJECT
            && config('community.railway_environment_id') === self::DEVELOPMENT_ENVIRONMENT
            && is_array($url) && ($url['scheme'] ?? '') === 'https'
            && ($url['host'] ?? '') === 'api-dev.cozyhealth.org'
            && ! isset($url['user']) && ! isset($url['pass'])
            && ! isset($url['query']) && ! isset($url['fragment'])
            && (! isset($url['port']) || $url['port'] === 443);
    }

    public function ready(): bool
    {
        return filled(config('community.guidelines_version'))
            && (config('community.release_ready') === true || $this->developmentTesting());
    }

    public function enabled(): bool
    {
        return $this->effective(FeatureFlag::where('key', 'community')->value('enabled') === true);
    }

    public function effective(bool $featureEnabled): bool
    {
        return $featureEnabled && $this->ready();
    }
}
