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

    public function state(User $user): array
    {
        $age = $this->age($user);
        $patientDocuments = app(DocumentConsentService::class)->configuration()['documents'];
        $patientConsentReady = collect(['tos', 'privacy_policy', 'health_data'])->every(fn ($type) => isset($patientDocuments[$type])
            && $patientDocuments[$type]['published'] && filled($patientDocuments[$type]['content'])
            && ! str_starts_with($patientDocuments[$type]['version'], 'draft-'));
        $reason = null;
        if (config('age.enabled')) {
            if ($user->age_disputed) $reason = 'age_disputed';
            elseif ($age === null || ! $user->residence_country) $reason = 'age_required';
            elseif ($age < 13) $reason = 'under_age';
            elseif ($age < 18) {
                if (! config('age.health_data_ready') || ! config('age.assurance_approval') || ! config('age.retention_approval')
                    || config('age.record_days') < 1 || ! in_array($user->residence_country, config('age.approved_countries'), true)) $reason = 'country_not_enabled';
                elseif (! $this->documentsReady()) $reason = 'consent_required';
                else {
                    $authorization = GuardianAuthorization::where('user_id', $user->id)->whereIn('status', ['authorized', 'withdrawal_confirmed'])->first();
                    if (! $authorization || $authorization->document_versions != $this->versions()) $reason = 'guardian_required';
                }
            }
            if (! $reason && ! $patientConsentReady) $reason = 'consent_required';
            if (! $reason && $age !== null && $age >= 13) {
                foreach (app(DocumentConsentService::class)->configuration()['documents'] as $type => $doc) {
                    if ((! ($doc['required'] ?? false) && ! in_array($type, ['tos', 'privacy_policy', 'health_data'])) || $type === 'marketing') continue;
                    if (! $doc['published'] || blank($doc['content']) || ! DB::table('user_consents')->where(['user_id' => $user->id,
                        'document_type' => $type, 'version' => $doc['version'], 'status' => 'accepted'])->exists()) { $reason = 'consent_required'; break; }
                }
            }
        }
        return ['owner_id' => $user->id, 'enforced' => (bool) config('age.enabled'), 'allowed' => $reason === null,
            'reason' => $reason, 'age_band' => $age === null ? 'unknown' : ($age < 13 ? 'under_13' : ($age < 18 ? 'minor' : 'adult')),
            'residence_country' => $user->residence_country, 'online_required' => $age === null || $age < 18,
            'email_verified' => $user->email_verified_at !== null,
            'patient_consent_ready' => $patientConsentReady,
            'invitations_enabled' => $this->invitationsReady()];
    }

    public function invitationsReady(): bool
    {
        return (bool) config('age.invitations_enabled') && filled(config('age.retention_approval'))
            && config('age.record_days') > 0 && $this->documentsReady() && $this->websiteReady();
    }

    public function websiteReady(): bool
    {
        $url = parse_url((string) config('age.website_url'));
        return is_array($url) && isset($url['host']) && ! isset($url['user']) && ! isset($url['pass'])
            && ! isset($url['query']) && ! isset($url['fragment']) && ($url['scheme'] ?? '') === 'https';
    }

    public function allows(User $user): bool { return $this->state($user)['allowed']; }

    public function require(User $user): void
    {
        $state = $this->state($user);
        if (! $state['allowed']) throw new HttpResponseException(response()->json([
            'message' => 'Complete the age and authorization steps before using private features.',
            'code' => $state['reason'], 'eligibility' => $state,
        ], 403));
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
