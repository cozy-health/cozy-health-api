<?php

namespace App\Services;

use App\Models\{GuardianAuthorization, User};
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Exceptions\HttpResponseException;

class AgeEligibility
{
    public const BLOCKING_CASE_KINDS = ['dob_correction', 'guardian_dispute'];

    public function age(User $user): ?int
    {
        if (! $user->date_of_birth) return null;
        return (int) CarbonImmutable::parse($user->date_of_birth->format('Y-m-d'), 'UTC')->diffInYears(CarbonImmutable::now('UTC')->startOfDay());
    }

    public function documents(): array
    {
        $documents = app(DocumentConsentService::class)->configuration()['documents'];
        $manifest = json_decode(file_get_contents(resource_path('legal/launch.json')), true, flags: JSON_THROW_ON_ERROR);
        // Guardian notice is a website informational document, separate from patient consent.
        if (isset($manifest['guardian_notice'])) $documents['guardian_notice'] = $manifest['guardian_notice'];
        return array_intersect_key($documents, array_flip(['tos', 'privacy_policy', 'health_data', 'guardian_notice']));
    }

    public function documentsReady(): bool
    {
        $documents = $this->documents();
        return count($documents) === 4 && collect($documents)->every(fn ($doc) => ($doc['published'] ?? false)
            && filled($doc['content'] ?? null) && ! str_starts_with($doc['version'], 'draft-'));
    }

    public function versions(): array
    {
        return array_map(fn ($doc) => $doc['version'], $this->documents());
    }

    /** Compatibility response for older clients; age onboarding is store-managed. */
    public function state(User $user): array
    {
        return ['owner_id' => $user->id, 'enforced' => false, 'allowed' => true,
            'reason' => null, 'age_band' => 'unknown', 'online_required' => false,
            'invitations_enabled' => false, 'retired' => true];
    }

    public function invitationsReady(): bool { return false; }

    public function websiteReady(): bool
    {
        $url = parse_url((string) config('age.website_url'));
        return is_array($url) && isset($url['host']) && ! isset($url['user']) && ! isset($url['pass'])
            && ! isset($url['query']) && ! isset($url['fragment']) && ($url['scheme'] ?? '') === 'https';
    }

    public function allows(User $user): bool { return app(DocumentConsentService::class)->patientConsentCurrent($user); }

    public function require(User $user): void
    {
        app(DocumentConsentService::class)->requirePatientConsent($user);
    }

    public function event(User $user, string $action, array $details = [], ?string $authorization = null, ?int $actor = null): void
    {
        DB::table('age_authorization_events')->insert(['user_id' => $user->id, 'authorization_id' => $authorization,
            'actor_id' => $actor, 'action' => $action, 'details' => encrypt(json_encode($details)), 'created_at' => now()]);
    }

    public function endAuthorizations(User $user, string $status): void
    {
        foreach ([['pending', 'confirmed'], ['authorized', 'withdrawal_confirmed']] as $states) {
            GuardianAuthorization::where('user_id', $user->id)->whereIn('status', $states)->update([
                'status' => $status, 'ended_at' => now(), 'token_hash' => null, 'session_hash' => null,
                'erase_at' => now()->addDays($states[0] === 'pending' ? 30 : max(1, (int) config('age.record_days')))]);
        }
    }
}
