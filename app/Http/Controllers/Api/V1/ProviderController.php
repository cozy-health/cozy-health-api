<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProviderRequestRequest;
use App\Http\Requests\ProviderVerifyRequest;
use App\Models\Provider;
use App\Models\ProviderConnection;
use App\Support\ApiResponse;
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
        $email = $validated['provider_email'] ?? $validated['email'];
        $code = (string) random_int(100000, 999999);
        $provider = Provider::where('email', $email)->where('is_active', 1)->first();
        $connection = ProviderConnection::updateOrCreate(['user_id' => $r->user()->id], [
            'provider_id' => $provider?->id,
            'provider_email' => $email,
            'provider_name' => $validated['provider_name'] ?? $provider?->name,
            'provider_phone' => $validated['provider_phone'] ?? $provider?->phone,
            'verification_code' => Hash::make($code),
            'verification_expires_at' => now()->addMinutes(15),
            'verified_at' => null,
        ]);
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
        $connection = $r->user()->providerConnection()->first();
        abort_unless($connection && $connection->verification_code, 422, 'No verification request was found.');
        abort_if($connection->verification_expires_at?->isPast(), 422, 'The verification code has expired.');
        abort_unless(Hash::check($r->code, $connection->verification_code), 422, 'The verification code is invalid.');
        $connection->update(['verification_code' => null, 'verification_expires_at' => null, 'verified_at' => now()]);
        $connection = $connection->fresh()->load('provider');
        app(\App\Services\Analytics\AnalyticsService::class)->track('provider_linked');

        return response()->json([
            'success' => true,
            'message' => 'Provider verified successfully.',
            'data' => $connection,
            ...$this->providerPayload($connection),
        ]);
    }

    public function destroy()
    {
        request()->user()->providerConnection()?->delete();

        return $this->success(null, 'Provider connection removed.');
    }

    private function providerPayload(?ProviderConnection $connection): array
    {
        return [
            'has_provider' => $connection?->verified_at !== null,
            'provider' => $connection === null ? null : [
                ...$connection->toArray(),
                'status' => $connection->verified_at === null ? 'pending' : 'verified',
                'provider_name' => $connection->provider_name ?? $connection->provider?->name,
                'provider_email' => $connection->provider_email,
                'provider_phone' => $connection->provider_phone ?? $connection->provider?->phone,
            ],
        ];
    }
}
