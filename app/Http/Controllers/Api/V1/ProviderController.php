<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProviderRequestRequest;
use App\Http\Requests\ProviderVerifyRequest;
use App\Models\Provider;
use App\Models\ProviderClientLink;
use App\Services\Analytics\AnalyticsService;
use App\Services\Provider\ProviderAudit;
use App\Support\ApiResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

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
        $connection = DB::transaction(function () use ($r, $email, $provider, $validated, $code) {
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
            $connection = $existing ?? new ProviderClientLink(['user_id' => $r->user()->id]);
            $connection->fill([
                'provider_id' => $provider?->id,
                'provider_email' => $email,
                'provider_name' => $validated['provider_name'] ?? $provider?->name,
                'provider_phone' => $validated['provider_phone'] ?? $provider?->phone,
                'verification_code' => Hash::make($code),
                'verification_expires_at' => now()->addMinutes(15),
                'verified_at' => null,
                'revoked_at' => null, 'linked_at' => now(),
                'consent_moods' => false, 'consent_journals' => false,
                'consent_quizzes' => false, 'consent_notes' => false,
            ])->save();

            return $connection;
        });
        $data = ['expires_in_minutes' => 15];
        if (app()->isLocal()) {
            $data['development_code'] = $code;
        }

        // Preserve the existing success envelope and add the fields mobile reads.
        return response()->json([
            'success' => true,
            'message' => 'Verification code created.',
            'data' => $data,
            'status' => 'pending',
            ...$this->providerPayload($connection->load('provider')),
        ]);
    }

    public function verify(ProviderVerifyRequest $r)
    {
        $connection = DB::transaction(function () use ($r) {
            $connection = $r->user()->providerConnection()->lockForUpdate()->first();
            abort_unless($connection && $connection->verification_code, 422, 'No verification request was found.');
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

    public function destroy()
    {
        DB::transaction(function () {
            $links = request()->user()->providerClientLinks()->whereNull('revoked_at')->lockForUpdate()->get();
            foreach ($links as $link) {
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
                'provider_verified' => (bool) $connection->provider?->is_verified,
                'consent_flags' => $connection->consentFlags(),
            ],
        ];
    }
}
