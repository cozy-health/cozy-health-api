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
            $device = PushDevice::updateOrCreate(
                ['user_id' => $request->user()->id, 'device_id' => $data['device_id']],
                [...$data, 'token_hash' => $hash, 'expires_at' => now()->addDays(90)],
            );

            return $this->success($device);
        });
    }

    public function destroy(Request $request, string $deviceId)
    {
        PushDevice::where('user_id', $request->user()->id)->where('device_id', $deviceId)->delete();

        return response()->json(['message' => 'Device unregistered.']);
    }
}
