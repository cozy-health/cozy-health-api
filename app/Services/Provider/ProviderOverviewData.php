<?php

namespace App\Services\Provider;

use App\Models\Provider;
use App\Services\AgeEligibility;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ProviderOverviewData
{
    // Call within a transaction to serialize reads with connection/consent changes.
    public function links(Provider $provider): Collection
    {
        return $provider->clientLinks()->active()->whereHas('user')->with('user')->orderByDesc('linked_at')->lockForUpdate()->get()
            ->filter(fn ($link) => app(AgeEligibility::class)->allows($link->user))->values();
    }

    public function shares(Provider $provider, Collection $links)
    {
        abort_unless($provider->is_verified, 403, 'Provider verification is required.');
        return DB::table('quiz_shares')->join('quiz_attempts as attempts', 'attempts.id', '=', 'quiz_shares.quiz_attempt_id')
            ->join('provider_client_links as links', 'links.id', '=', 'quiz_shares.link_id')
            ->join('users', 'users.id', '=', 'quiz_shares.user_id')
            ->where('quiz_shares.provider_id', $provider->id)
            ->whereIn('quiz_shares.link_id', $links->where('consent_quizzes', true)->pluck('id'))
            ->whereNull('quiz_shares.revoked_at')->whereNull('attempts.deleted_at')->where('attempts.is_deleted', false)->whereNotNull('attempts.validated_at')
            ->whereColumn('links.user_id', 'quiz_shares.user_id')
            ->whereColumn('attempts.user_id', 'quiz_shares.user_id');
    }
}
