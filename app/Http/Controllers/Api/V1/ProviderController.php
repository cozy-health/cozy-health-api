<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProviderRequestRequest;
use App\Http\Requests\ProviderVerifyRequest;
use App\Mail\ProviderInvitationMail;
use App\Models\Provider;
use App\Models\ProviderClientLink;
use App\Services\Analytics\AnalyticsService;
use App\Services\Provider\InvitationCode;
use App\Services\Provider\ProviderAudit;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

class ProviderController extends Controller
{
    use ApiResponse;

    public function show()
    {
        $connection = request()->user()->providerConnection()->with('provider')->first();

        return response()->json($this->providerPayload($connection));
    }

    public function requestCode(ProviderRequestRequest $r)
    {
        $validated = $r->validated();
        $email = strtolower(trim($validated['provider_email'] ?? $validated['email']));
        $code = (string) random_int(100000, 999999);
        $provider = Provider::whereRaw('LOWER(email) = ?', [$email])->first();
        abort_if($provider && ! $provider->is_active, 422, 'This provider is unavailable.');
        $connection = DB::transaction(function () use ($r, $email, $provider, $validated, &$code) {
            // Serialize requests for a patient, including retries of a revoked invitation.
            $r->user()->newQuery()->whereKey($r->user()->id)->lockForUpdate()->firstOrFail();
            $existing = ProviderClientLink::where('user_id', $r->user()->id)
                ->where(function ($query) use ($email, $provider) {
                    $query->where('provider_email', $email);
                    if ($provider) {
                        $query->orWhere('provider_id', $provider->id);
                    }
                })->lockForUpdate()->first();
            if ($existing && ! $existing->revoked_at && $existing->verified_at) {
                return $existing;
            }
            if (($validated['verification_actor'] ?? 'patient') === 'provider') {
                abort_if($existing?->invitation_sent_at?->gt(now()->subMinute()), 429, 'Wait one minute before sending another invitation.');
            }
            while ($existing?->verification_code && Hash::check($code, $existing->verification_code)) {
                $code = (string) random_int(100000, 999999);
            }
            $connection = $existing ?? new ProviderClientLink(['user_id' => $r->user()->id]);
            $connection->fill([
                'provider_id' => $provider?->id,
                'provider_email' => $email,
                'provider_name' => $validated['provider_name'] ?? $provider?->name,
                'provider_phone' => $validated['provider_phone'] ?? $provider?->phone,
                'verification_code' => Hash::make($code),
                'invitation_code_secret' => ($validated['verification_actor'] ?? 'patient') === 'provider' ? $code : null,
                'verification_actor' => $validated['verification_actor'] ?? 'patient',
                'invitation_code_lookup' => ($validated['verification_actor'] ?? 'patient') === 'provider' ? InvitationCode::lookup($email, $code) : null,
                'invitation_sent_at' => ($validated['verification_actor'] ?? 'patient') === 'provider' ? now() : null,
                'provider_acknowledged_at' => null,
                'verification_expires_at' => now()->addMinutes(15),
                'verified_at' => null,
                'revoked_at' => null, 'linked_at' => now(),
                'consent_moods' => false, 'consent_journals' => false,
                'consent_quizzes' => false, 'consent_notes' => false,
            ]);
            if ($connection->verification_actor === 'provider') {
                foreach (['moods', 'journals', 'quizzes', 'notes'] as $type) {
                    $connection->{'consent_'.$type} = (bool) ($validated['consent_flags'][$type] ?? false);
                }
            }
            $connection->save();

            return $connection;
        });
        if ($connection->verification_actor === 'provider' && ! $connection->verified_at) {
            try {
                Mail::to($email)->queue(new ProviderInvitationMail($connection->id, $code));
            } catch (\Throwable $exception) {
                ProviderClientLink::whereKey($connection->id)->where('invitation_code_lookup', InvitationCode::lookup($email, $code))->update(['invitation_sent_at' => null]);
                // Avoid logging a queued mailable or exception payload that could contain the code.
                abort(503, 'The invitation email could not be queued. Please try again.');
            }
        }
        $data = ['expires_in_minutes' => 15];
        if ($connection->verification_actor !== 'provider' && app()->isLocal() && $connection->verification_code && Hash::check($code, $connection->verification_code)) {
            $data['development_code'] = $code;
        }

        // Preserve the existing success envelope and add the fields mobile reads.
        return response()->json([
            'success' => true,
            'message' => $connection->verified_at ? 'Provider already connected.' : ($connection->verification_actor === 'provider' ? 'Invitation email queued.' : 'Verification code created.'),
            'data' => $data,
            'status' => $connection->verified_at ? 'verified' : 'pending',
            ...$this->providerPayload($connection->load('provider')),
        ]);
    }

    public function verify(ProviderVerifyRequest $r)
    {
        $connection = DB::transaction(function () use ($r) {
            $connection = $r->user()->providerConnection()->lockForUpdate()->first();
            abort_unless($connection && $connection->verification_code, 422, 'No verification request was found.');
            abort_if($connection->verification_actor === 'provider', 422, 'Your provider must confirm this invitation in their dashboard.');
            abort_if($connection->verification_expires_at?->isPast(), 422, 'The verification code has expired.');
            abort_unless(Hash::check($r->code, $connection->verification_code), 422, 'The verification code is invalid.');
            $connection->update(['verification_code' => null, 'verification_expires_at' => null,
                'verified_at' => now(), 'linked_at' => now(), 'consent_moods' => false,
                'consent_journals' => false, 'consent_quizzes' => false, 'consent_notes' => false]);

            return $connection;
        });
        $connection = $connection->fresh()->load('provider');
        app(AnalyticsService::class)->track('provider_linked');

        return response()->json([
            'success' => true,
            'message' => 'Provider verified successfully.',
            'data' => $connection,
            ...$this->providerPayload($connection),
        ]);
    }

    public function cancelInvitation(Request $request, string $linkId)
    {
        return DB::transaction(function () use ($request, $linkId) {
            $link = $request->user()->providerClientLinks()->whereKey($linkId)->lockForUpdate()->firstOrFail();
            abort_if($link->verified_at && ! $link->revoked_at, 409, 'This provider has already accepted. Refresh the connection before making changes.');
            if (! $link->revoked_at) {
                $link->update(['revoked_at' => now(), 'verification_code' => null,
                    'invitation_code_lookup' => null, 'verification_expires_at' => null]);
                if ($link->provider_id) {
                    ProviderAudit::record($request, $link, 'invitation_cancelled', 'client', (string) $link->id);
                }
            }

            return response()->json(['message' => 'Invitation cancelled.']);
        });
    }

    public function destroy()
    {
        DB::transaction(function () {
            $link = request()->user()->providerConnection()->lockForUpdate()->first();
            if ($link) {
                DB::table('quiz_shares')->where('link_id', $link->id)->whereNull('revoked_at')->update(['revoked_at' => now(), 'updated_at' => now()]);
                $link->update(['revoked_at' => now()]);
                ProviderAudit::record(request(), $link, 'revoked', 'client', (string) $link->id);
            }
        });

        return $this->success(null, 'Provider connection removed.');
    }

    private function providerPayload(?ProviderClientLink $connection): array
    {
        return [
            'has_provider' => $connection?->verified_at !== null,
            'provider' => $connection === null ? null : [
                ...$connection->toArray(),
                'status' => $connection->verified_at === null ? 'pending' : 'verified',
                'provider_name' => $connection->provider_name ?? $connection->provider?->name,
                'provider_email' => $connection->provider_email,
                'provider_phone' => $connection->provider_phone ?? $connection->provider?->phone,
                'link_id' => $connection->id,
                'invitation_portal_url' => config('services.provider.portal_url'),
                'invitation_code' => $connection->verification_actor === 'provider'
                    && ! $connection->verified_at && ! $connection->revoked_at && $connection->verification_expires_at?->isFuture()
                    ? $connection->invitation_code_secret : null,
                'invitation_expires_at' => $connection->verification_actor === 'provider' && ! $connection->verified_at && ! $connection->revoked_at
                    ? $connection->verification_expires_at?->toIso8601String() : null,
                'provider_verified' => (bool) $connection->provider?->is_verified,
                'consent_flags' => $connection->consentFlags(),
            ],
        ];
    }
}
