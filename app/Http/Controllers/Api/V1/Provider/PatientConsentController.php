<?php

namespace App\Http\Controllers\Api\V1\Provider;

use App\Http\Controllers\Controller;
use App\Models\ProviderClientLink;
use App\Services\Provider\ProviderAudit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PatientConsentController extends Controller
{
    public function index(Request $request)
    {
        return response()->json(['links' => $request->user()->providerClientLinks()->with('provider')->orderByDesc('id')->get()
            ->map(fn ($link) => $this->payload($link))]);
    }

    public function update(Request $request, string $linkId)
    {
        $data = $request->validate(['consent_type' => ['required', Rule::in(['moods', 'journals', 'quizzes', 'notes'])],
            'value' => ['required', function ($attribute, $value, $fail) {
                if (! is_bool($value)) {
                    $fail('The value must be a boolean.');
                }
            }]]);

        return DB::transaction(function () use ($request, $linkId, $data) {
            $link = $request->user()->providerClientLinks()->active()->whereKey($linkId)->lockForUpdate()->firstOrFail();
            if ($data['consent_type'] === 'quizzes' && ! $data['value']) {
                DB::table('quiz_shares')->where('link_id', $link->id)->whereNull('revoked_at')->update(['revoked_at' => now(), 'updated_at' => now()]);
            }
            $link->update(['consent_'.$data['consent_type'] => $data['value']]);
            ProviderAudit::record($request, $link, 'consent_updated', 'consent', (string) $link->id);

            return response()->json(['link' => $this->payload($link->load('provider'))]);
        });
    }

    public function destroy(Request $request, string $linkId)
    {
        return DB::transaction(function () use ($request, $linkId) {
            $link = $request->user()->providerClientLinks()->whereNull('revoked_at')->whereKey($linkId)->lockForUpdate()->firstOrFail();
            DB::table('quiz_shares')->where('link_id', $link->id)->whereNull('revoked_at')->update(['revoked_at' => now(), 'updated_at' => now()]);
            $link->update(['revoked_at' => now()]);
            ProviderAudit::record($request, $link, 'revoked', 'client', (string) $link->id);

            return response()->json(['message' => 'Provider access revoked.']);
        });
    }

    private function payload(ProviderClientLink $link): array
    {
        return ['id' => $link->id, 'provider_id' => $link->provider_id,
            'provider' => ['name' => $link->provider?->name ?? $link->provider_name,
                'is_verified' => (bool) $link->provider?->is_verified],
            'provider_email' => $link->provider_email, 'verified_at' => $link->verified_at,
            'status' => $link->revoked_at ? 'revoked' : ($link->verified_at ? 'verified' : 'pending'),
            'consent_flags' => $link->consentFlags(), 'linked_at' => $link->linked_at, 'revoked_at' => $link->revoked_at];
    }
}
