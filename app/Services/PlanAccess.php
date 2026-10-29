<?php

namespace App\Services;

use App\Models\SubscriptionStatus;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;

final class PlanAccess
{
    public const STANDARD = ['written_journal', 'mood_checkin', 'community_read'];
    public const PREMIUM = ['voice', 'provider_connect', 'provider_chat', 'journal_share', 'assessments', 'challenges', 'community_participate', 'trends_dashboard'];

    public function current(User $user): array
    {
        $grant = app(DevelopmentTestAccess::class)->activeFor($user->id);
        $subscription = $grant ?? SubscriptionStatus::withoutGlobalScope('user')->where('user_id', $user->id)
            ->where('status', 'active')->whereIn('plan', ['standard', 'student', 'premium'])
            ->where(fn ($q) => $q->whereNull('started_at')->orWhere('started_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->orderByDesc('expires_at')->first()?->toArray();
        $plan = $subscription['plan'] ?? null;
        $features = $plan ? self::STANDARD : [];
        if (in_array($plan, ['student', 'premium'], true)) $features = [...$features, ...self::PREMIUM];
        return ['enforcement_enabled' => (bool) config('plan_access.enabled'), 'plan' => $plan,
            'features' => ['emergency', 'existing_records', ...$features],
            'expires_at' => $subscription['expires_at'] ?? null,
            'source' => $subscription['source'] ?? null];
    }

    public function allows(User $user, string $feature): bool
    {
        return !config('plan_access.enabled') || in_array($feature, $this->current($user)['features'], true);
    }

    public function require(User $user, string $feature, ?string $message = null): void
    {
        if (!config('plan_access.enabled')) return;
        if (in_array($feature, $this->current($user)['features'], true)) return;
        $premium = in_array($feature, self::PREMIUM, true);
        throw new HttpResponseException(response()->json(['success' => false, 'code' => 'plan_required',
            'feature' => $feature, 'allowed_plans' => $premium ? ['student', 'premium'] : ['standard', 'student', 'premium'],
            'message' => $message ?? ($premium ? 'This action requires an active Student or Premium plan. Your existing records remain available.'
                : 'An active plan is needed for this action. Your existing records and emergency help remain available.')], 403));
    }

    public function journal(User $user, array $data, ?object $existing = null): void
    {
        $voice = ($data['type'] ?? $existing?->type) === 'voice';
        foreach (['audio_attachment_id', 'voice_url', 'voice_duration', 'transcription'] as $field) {
            if (!empty($data[$field])) $voice = true;
        }
        $this->require($user, $voice ? 'voice' : 'written_journal');
    }
}
