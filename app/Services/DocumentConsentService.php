<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DocumentConsentService
{
    public function configuration(bool $provider = false): array
    {
        $key = $provider ? 'provider_documents' : 'documents';
        $documents = Setting::find('consent.'.$key)?->value ?? config('consent.'.$key);
        unset($documents['ai_disclosure']);
        $manifest = json_decode(file_get_contents(resource_path('legal/launch.json')), true, flags: JSON_THROW_ON_ERROR);
        foreach ($documents as $type => &$document) {
            $launch = $manifest[$key][$type] ?? null;
            if ($launch && $document['version'] === $launch['version'] && $document['published']) $document['url'] = $launch['url'];
            else $document['url'] = 'https://cozyhealth.org/'.($provider ? 'providers/' : '').match ($type) { 'tos' => 'terms', 'privacy_policy' => 'privacy', 'health_data' => 'health-data-consent', 'ugc_guidelines' => 'community-standards', default => 'marketing' };
        }
        unset($document);
        $enabled = Setting::find('consent.enforce_blocking')?->value === true;
        // A misplaced flag must never block access behind unpublished drafts.
        $ready = collect($documents)->filter(fn ($doc, $type) => $type !== 'marketing' && $doc['required'])
            ->every(fn ($doc) => $doc['published'] && ! str_starts_with($doc['version'], 'draft-') && filled($doc['content']));
        return ['enforce_blocking' => $enabled && $ready, 'documents' => $documents,
            'current_versions' => array_map(fn ($doc) => $doc['version'], $documents)];
    }

    public function marketingAllowed(User $user): bool
    {
        $document = $this->configuration()['documents']['marketing'];
        if (!$document['published'] || blank($document['content']) || str_starts_with($document['version'], 'draft-')) return false;
        $version = $document['version'];
        return $user->is_active && DB::table('user_consents')->where(['user_id' => $user->id, 'document_type' => 'marketing', 'version' => $version, 'status' => 'accepted'])->exists();
    }
}
