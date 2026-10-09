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
        $enabled = Setting::find('consent.enforce_blocking')?->value === true;
        // A misplaced flag must never block access behind unpublished drafts.
        $ready = collect($documents)->filter(fn ($doc, $type) => $type !== 'marketing' && $doc['required'])
            ->every(fn ($doc) => $doc['published'] && ! str_starts_with($doc['version'], 'draft-') && filled($doc['content']));
        return ['enforce_blocking' => $enabled && $ready, 'documents' => $documents,
            'current_versions' => array_map(fn ($doc) => $doc['version'], $documents)];
    }

    public function marketingAllowed(User $user): bool
    {
        $version = $this->configuration()['current_versions']['marketing'];
        return $user->is_active && DB::table('user_consents')->where(['user_id' => $user->id, 'document_type' => 'marketing', 'version' => $version, 'status' => 'accepted'])->exists();
    }
}
