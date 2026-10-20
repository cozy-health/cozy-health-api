<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\PushDevice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PushDeviceController extends ApiController
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'device_id' => ['required', 'string', 'max:128', 'regex:/^[a-zA-Z0-9._-]+$/'],
            'platform' => ['required', Rule::in(['ios', 'android'])],
            'provider' => ['required', Rule::in(['fcm', 'apns'])],
            'environment' => ['sometimes', Rule::in(['production', 'sandbox'])],
            'token' => ['required', 'string', 'min:16', 'max:4096', 'regex:/^\S+$/'],
        ]);
        if ($data['provider'] === 'apns') {
            $request->validate(['platform' => ['in:ios'], 'token' => ['regex:/^[a-fA-F0-9]{64}$/']]);
            $data['token'] = strtolower($data['token']);
        }
        $hash = hash('sha256', $data['token']);

        return DB::transaction(function () use ($request, $data, $hash) {
            // Serialize registrations for this account; never silently reassign
            // a token already registered to another patient's account.
            $request->user()->newQuery()->whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $existing = PushDevice::where('token_hash', $hash)->lockForUpdate()->first();
            if ($existing && ($existing->user_id !== $request->user()->id || $existing->device_id !== $data['device_id'])) {
                return $this->error('This token is already registered. Unregister it before reassignment.', 'TOKEN_IN_USE', [], 409);
            }
            $cleanup = bin2hex(random_bytes(32));
            $device = PushDevice::updateOrCreate(
                ['user_id' => $request->user()->id, 'device_id' => $data['device_id']],
                [...$data, 'token_hash' => $hash, 'cleanup_hash' => hash('sha256', $cleanup), 'expires_at' => now()->addDays(90)],
            );

            return $this->success([...$device->toArray(), 'cleanup_token' => $cleanup]);
        });
    }

    public function destroy(Request $request, string $deviceId)
    {
        PushDevice::where('user_id', $request->user()->id)->where('device_id', $deviceId)->delete();

        return response()->json(['message' => 'Device unregistered.']);
    }

    // Revocation-only capability survives an expired login. It grants no account/data access.
    public function cleanup(Request $request)
    {
        $data = $request->validate(['device_id' => 'required|string|max:128', 'cleanup_token' => 'required|string|size:64|regex:/^[a-f0-9]+$/']);
        PushDevice::where('device_id', $data['device_id'])->where('cleanup_hash', hash('sha256', $data['cleanup_token']))->delete();
        return response()->json(['message' => 'Registration cleanup completed.']);
    }
}
