<?php

namespace App\Http\Controllers\Api\V1\Provider;

use App\Http\Controllers\Controller;
use App\Mail\ProviderPasswordResetMail;
use App\Mail\ProviderPasswordResetConfirmationMail;
use App\Models\Provider;
use App\Models\ProviderClientLink;
use App\Models\ProviderAccessLog;
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
            'password' => ['required', 'confirmed', new \App\Rules\StrongPassword],
            'practice' => ['nullable', 'string', 'max:255'],
            'license_number' => ['nullable', 'required_with:license_state', 'string', 'max:255', Rule::unique('providers')->where('license_state', $request->input('license_state'))],
            'license_state' => ['nullable', 'required_with:license_number', 'string', 'max:255'],
        ]);
        $data['password_hash'] = $data['password'];
        unset($data['password']);
        $provider = DB::transaction(function () use ($data, $request) {
            $provider = Provider::create($data);
            $links = ProviderClientLink::whereNull('provider_id')->where('provider_email', $provider->email)
                ->lockForUpdate()->get();
            foreach ($links as $link) {
                $link->update(['provider_id' => $provider->id]);
                ProviderAccessLog::create(['provider_id' => $provider->id, 'user_id' => $link->user_id, 'link_id' => $link->id,
                    'action' => 'link_attached', 'resource_type' => 'client', 'resource_id' => (string) $link->id,
                    'ip_address' => $request->ip(), 'user_agent' => $request->userAgent()]);
            }
            return $provider;
        });
        $session = app(\App\Services\AuthSessions::class)->issue($provider, $request, 'cozy-provider');
        try {
            Mail::to($provider->email)->send(new \App\Mail\ProviderWelcomeMail($provider));
        } catch (\Throwable) {
            \Log::warning('Provider welcome email could not be queued.');
        }
        return $this->success([
            'provider' => $provider->refresh(),
            ...$session,
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
        $deviceName = $data['device_name'] ?? 'cozy-provider';
        // Browser bridge sessions and their underlying credentials expire together.
        $expiresAt = $deviceName === 'cozy-provider-dashboard' ? now()->addMinutes(30) : now()->addMinutes(60);
        return $this->success([
            'provider' => $provider,
            ...app(\App\Services\AuthSessions::class)->issue($provider, $request, $deviceName),
        ], 'Logged in successfully.');
    }

    public function logout(Request $request)
    {
        app(\App\Services\AuthSessions::class)->revokeOne($request->user(), $request->user()->currentAccessToken()->id);
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
                ['token' => Hash::make($code), 'created_at' => now(), 'expires_at' => now()->addMinutes(15), 'failed_attempts' => 0]
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
            'password' => ['required', 'confirmed', new \App\Rules\StrongPassword],
        ]);
        $provider = DB::transaction(function () use ($data) {
            $record = DB::table('provider_password_reset_tokens')->where('email', $data['email'])->lockForUpdate()->first();
            $provider = Provider::where('email', $data['email'])->lockForUpdate()->first();
            if (!$record || !$provider || !$provider->is_active || now()->gte($record->expires_at) || $record->failed_attempts >= 5) {
                return null;
            }
            if (!Hash::check($data['token'], $record->token)) {
                DB::table('provider_password_reset_tokens')->where('email', $data['email'])->increment('failed_attempts');
                return null;
            }
            $provider->update(['password_hash' => $data['password']]);
            app(\App\Services\AuthSessions::class)->revoke($provider);
            DB::table('provider_password_reset_tokens')->where('email', $data['email'])->delete();
            return $provider;
        });
        if (!$provider) {
            return response()->json(['success' => false, 'message' => 'Invalid or expired reset code.'], 400);
        }
        try {
            Mail::to($provider->email)->send(new ProviderPasswordResetConfirmationMail($provider));
        } catch (\Throwable) {
            \Log::warning('Provider password reset confirmation could not be queued.');
            return $this->success(null, 'Your password has been successfully reset. The confirmation email could not be queued.');
        }
        return $this->success(null, 'Your password has been successfully reset.');
    }
}
