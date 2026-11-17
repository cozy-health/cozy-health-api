<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

final class DevelopmentTestAccess
{
    public function enabled(): bool
    {
        $url = parse_url((string) config('app.url'));
        return config('development_access.enabled') === true
            && config('development_access.railway_project_id') === CommunityAvailability::DEVELOPMENT_PROJECT
            && config('development_access.railway_environment_id') === CommunityAvailability::DEVELOPMENT_ENVIRONMENT
            && is_array($url) && ($url['scheme'] ?? '') === 'https'
            && ($url['host'] ?? '') === 'api-dev.cozyhealth.org'
            && !isset($url['user']) && !isset($url['pass']) && !isset($url['query']) && !isset($url['fragment'])
            && (!isset($url['port']) || $url['port'] === 443);
    }

    public function activeFor(int $userId): ?array
    {
        if (!$this->enabled()) return null;
        $grant = DB::table('development_access_grants')->where('user_id', $userId)
            ->whereNull('revoked_at')->where('starts_at', '<=', now())->where('expires_at', '>', now())
            ->orderByDesc('created_at')->first();
        if (!$grant) return null;
        return ['id' => $grant->id, 'user_id' => $userId, 'plan' => $grant->plan, 'status' => 'active',
            'platform' => 'development_test', 'source' => 'development_test', 'is_development_test' => true,
            'started_at' => Carbon::parse($grant->starts_at)->toIso8601String(), 'expires_at' => Carbon::parse($grant->expires_at)->toIso8601String(),
            'notice' => 'Temporary development test access. No payment was collected.'];
    }
}
