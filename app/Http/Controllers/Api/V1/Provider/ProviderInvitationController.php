<?php

namespace App\Http\Controllers\Api\V1\Provider;

use App\Http\Controllers\Controller;
use App\Models\ProviderClientLink;
use App\Services\Provider\InvitationCode;
use App\Services\Provider\ProviderAudit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class ProviderInvitationController extends Controller
{
    private function find(Request $request): ProviderClientLink
    {
        $email = strtolower(trim($request->user()->email));
        // The keyed lookup narrows candidates without storing the short code in plaintext.
        $matches = ProviderClientLink::where('provider_email', $email)
            ->where('verification_actor', 'provider')->whereNull('verified_at')->whereNull('revoked_at')
            ->where('invitation_code_lookup', InvitationCode::lookup($email, $request->string('code')->toString()))
            ->where('verification_expires_at', '>', now())->whereHas('user')
            ->lockForUpdate()->get();
        abort_unless($matches->count() === 1, 422, 'This code is invalid, expired, or no longer available. Ask the patient to send a new invitation.');
        $link = $matches->first();
        abort_unless(Hash::check($request->code, $link->verification_code), 422, 'This code is no longer available.');
        abort_if($link->provider_id && (int) $link->provider_id !== (int) $request->user()->id, 422, 'This invitation is no longer available.');
        abort_unless($link->verification_expires_at->isFuture(), 422, 'This code has expired. Ask the patient to send a new invitation.');
        return $link;
    }

    public function preview(Request $request)
    {
        $request->validate(['code' => ['required', 'string', 'regex:/^[0-9]{6}$/']]);
        return DB::transaction(function () use ($request) {
            $link = $this->find($request);
            return response()->json(['data' => ['invitation_id' => $link->id,
                'patient_name' => $link->user->name, 'consent_flags' => $link->consentFlags(),
                'expires_at' => $link->verification_expires_at->toIso8601String()]]);
        });
    }

    public function connect(Request $request)
    {
        $request->validate([
            'code' => ['required', 'string', 'regex:/^[0-9]{6}$/'],
            'invitation_id' => ['required', 'integer'],
            'care_acknowledged' => ['required', 'accepted'],
            'revocation_acknowledged' => ['required', 'accepted'],
            'consent_flags' => ['required', 'array:moods,journals,quizzes,notes'],
            'consent_flags.moods' => ['required', 'boolean'], 'consent_flags.journals' => ['required', 'boolean'],
            'consent_flags.quizzes' => ['required', 'boolean'], 'consent_flags.notes' => ['required', 'boolean'],
        ]);
        return DB::transaction(function () use ($request) {
            $link = $this->find($request);
            abort_unless($link->id === (int) $request->invitation_id, 409, 'The invitation changed. Review it again.');
            foreach ($link->consentFlags() as $type => $allowed) {
                abort_unless($allowed === (bool) $request->input('consent_flags.'.$type), 409, 'The patient changed their sharing choices. Review the invitation again.');
            }
            $link->update(['provider_id' => $request->user()->id, 'verified_at' => now(), 'linked_at' => now(),
                'provider_acknowledged_at' => now(), 'verification_code' => null,
                'invitation_code_lookup' => null, 'verification_expires_at' => null]);
            ProviderAudit::record($request, $link, 'invitation_connected', 'client', (string) $link->id);
            return response()->json(['message' => 'Patient connected.', 'data' => ['user_id' => $link->user_id]]);
        }, 3);
    }
}
