<?php

namespace App\Http\Controllers\Api\V1\Provider;

use App\Http\Controllers\Controller;
use App\Mail\ProviderPasswordResetMail;
use App\Mail\ProviderPasswordResetConfirmationMail;
use App\Models\Provider;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProviderAuthController extends Controller
{
    use ApiResponse;

    private function normalizeEmail(Request $request): void
    {
        if (is_string($request->input('email'))) {
            $request->merge(['email' => strtolower(trim($request->input('email')))]);
        }
    }

    public function register(Request $request)
    {
        $this->normalizeEmail($request);
        if (is_string($request->input('license_state'))) {
            $request->merge(['license_state' => strtoupper(trim($request->input('license_state')))]);
        }
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', 'unique:providers,email'],
            'password' => ['required', 'string', 'min:8', 'max:255', 'confirmed'],
            'practice' => ['nullable', 'string', 'max:255'],
            'license_number' => ['nullable', 'required_with:license_state', 'string', 'max:255', Rule::unique('providers')->where('license_state', $request->input('license_state'))],
            'license_state' => ['nullable', 'required_with:license_number', 'string', 'max:255'],
        ]);
        $data['password_hash'] = $data['password'];
        unset($data['password']);
        $provider = Provider::create($data);
        return $this->success([
            'provider' => $provider->refresh(),
            'token' => $provider->createToken('cozy-provider')->plainTextToken,
        ], 'Account created successfully.', 201);
    }

    public function login(Request $request)
    {
        $this->normalizeEmail($request);
        $data = $request->validate([
            'email' => ['required', 'email', 'max:190'],
            'password' => ['required', 'string', 'max:255'],
            'device_name' => ['sometimes', 'string', 'max:255'],
        ]);
        $provider = Provider::where('email', $data['email'])->first();
        if (!$provider || !$provider->password_hash || !Hash::check($data['password'], $provider->password_hash)) {
            throw ValidationException::withMessages(['email' => ['The provided credentials are incorrect.']]);
        }
        abort_unless($provider->is_active, 403, 'This account has been disabled.');
        return $this->success([
            'provider' => $provider,
            'token' => $provider->createToken($data['device_name'] ?? 'cozy-provider')->plainTextToken,
        ], 'Logged in successfully.');
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();
        return $this->success(null, 'Logged out successfully.');
    }

    public function me(Request $request)
    {
        return $this->success(['provider' => $request->user()]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'practice' => ['sometimes', 'nullable', 'string', 'max:255'],
            'timezone' => ['sometimes', 'required', 'timezone'],
        ]);
        $request->user()->update($data);
        return $this->success(['provider' => $request->user()->refresh()], 'Profile updated successfully.');
    }

    public function forgotPassword(Request $request)
    {
        $this->normalizeEmail($request);
        $data = $request->validate(['email' => ['required', 'email', 'max:190']]);
        $provider = Provider::where('email', $data['email'])->where('is_active', true)->first();
        if ($provider) {
            $code = (string) random_int(100000, 999999);
            DB::table('provider_password_reset_tokens')->updateOrInsert(
                ['email' => $provider->email],
                ['token' => Hash::make($code), 'created_at' => now(), 'expires_at' => now()->addMinutes(15)]
            );
            Mail::to($provider->email)->send(new ProviderPasswordResetMail($provider, $code));
        }
        return $this->success(null, 'If your email is registered, you will receive a password reset code shortly.');
    }

    public function resetPassword(Request $request)
    {
        $this->normalizeEmail($request);
        $data = $request->validate([
            'email' => ['required', 'email', 'max:190'],
            'token' => ['required', 'string', 'regex:/^[0-9]{6}$/'],
            'password' => ['required', 'string', 'min:8', 'max:255', 'confirmed'],
        ]);
        $provider = DB::transaction(function () use ($data) {
            $record = DB::table('provider_password_reset_tokens')->where('email', $data['email'])->lockForUpdate()->first();
            $provider = Provider::where('email', $data['email'])->lockForUpdate()->first();
            if (!$record || !$provider || !$provider->is_active || now()->gte($record->expires_at) || !Hash::check($data['token'], $record->token)) {
                return null;
            }
            $provider->update(['password_hash' => $data['password']]);
            $provider->tokens()->delete();
            DB::table('provider_password_reset_tokens')->where('email', $data['email'])->delete();
            return $provider;
        });
        if (!$provider) {
            return response()->json(['success' => false, 'message' => 'Invalid or expired reset code.'], 400);
        }
        Mail::to($provider->email)->send(new ProviderPasswordResetConfirmationMail($provider));
        return $this->success(null, 'Your password has been successfully reset.');
    }
}
