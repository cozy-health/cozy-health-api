<?php

namespace App\Services;

use App\Models\Provider;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class AuthSessions
{
    public function table($owner): string
    {
        return $owner instanceof Provider ? 'provider_refresh_tokens' : 'refresh_tokens';
    }

    public function issue($owner, Request $request, ?string $name = null, bool $notify = true, ?string $verifiedDeviceHash = null): array
    {
        $request->validate(['device_id' => ['sometimes', 'string', 'max:120'], 'device_name' => ['sometimes', 'string', 'max:120'],
            'device_proof' => ['sometimes', 'nullable', 'string', 'size:64', 'regex:/^[a-f0-9]+$/']]);
        $name = mb_substr($name ?? $request->input('device_name', 'cozy-mobile'), 0, 120);
        $security = app(SignInSecurity::class);
        if ($security->requiresProtection($owner) && $verifiedDeviceHash === null) {
            $verifiedDeviceHash = $security->trustedHash($owner, $request->input('device_proof'));
            if ($verifiedDeviceHash === null) return $security->begin($owner, $request, $name);
        }
        $expires = now()->addMinutes($name === 'cozy-provider-dashboard' ? 30 : 60);
        $pair = DB::transaction(function () use ($owner, $request, $name, $expires, $verifiedDeviceHash) {
            $locked = $owner->newQuery()->whereKey($owner->id)->lockForUpdate()->first();
            abort_unless($locked && $locked->is_active, 403, 'This account has been disabled.');
            $token = $owner->createToken($name, ['*'], $expires);
            $token->accessToken->forceFill(['ip_address' => $request->ip()])->save();
            if ($verifiedDeviceHash !== null) {
                $token->accessToken->forceFill(['security_device_hash' => $verifiedDeviceHash])->save();
                DB::table('known_devices')->where('owner_type', $owner->getMorphClass())->where('owner_id', $owner->id)
                    ->where('proof_hash', $verifiedDeviceHash)->update(['last_used_at' => now()]);
            }
            $refresh = bin2hex(random_bytes(32));
            DB::table($this->table($owner))->insert([
                'user_id' => $owner->id, 'access_token_id' => $token->accessToken->id,
                'token_hash' => hash('sha256', $refresh), 'device_name' => $name,
                'ip_address' => $request->ip(), 'expires_at' => now()->addDays(30), 'created_at' => now(),
            ]);
            return ['token' => $token->plainTextToken, 'refresh_token' => $refresh,
                'expires_at' => $expires->toIso8601String(), 'refresh_expires_at' => now()->addDays(30)->toIso8601String()];
        });
        if ($notify && $owner instanceof User && $request->filled('device_id')) {
            $hash = hash('sha256', (string) $request->input('device_id'));
            $new = DB::table('known_devices')->insertOrIgnore([
                'owner_type' => $owner->getMorphClass(), 'owner_id' => $owner->id,
                'device_hash' => $hash, 'created_at' => now(),
            ]);
            if ($new && $owner->email_verified_at && ! $owner->email_invalid) {
                try { Mail::to($owner->email)->send(new \App\Mail\NewDeviceLoginMail($name, $request->ip(), now()->toIso8601String())); }
                catch (\Throwable) { \Log::warning('New-device notification could not be queued.'); }
            }
        }
        return [...$pair, ...($owner instanceof User ? ['eligibility' => app(AgeEligibility::class)->state($owner)] : [])];
    }

    public function revoke($owner, ?int $except = null): void
    {
        if (\Illuminate\Support\Facades\Schema::hasColumn('known_devices', 'proof_hash')) {
            $keep = $except !== null ? $owner->tokens()->whereKey($except)->value('security_device_hash') : null;
            DB::table('known_devices')->where('owner_type', $owner->getMorphClass())->where('owner_id', $owner->id)
                ->when($keep !== null, fn ($query) => $query->where(fn ($query) => $query->whereNull('proof_hash')->orWhere('proof_hash', '!=', $keep)))->delete();
            DB::table('signin_challenges')->where('owner_type', $owner->getMorphClass())->where('owner_id', $owner->id)
                ->update(['consumed_at' => now(), 'settings_used_at' => now()]);
        }
        DB::table($this->table($owner))->where('user_id', $owner->id)->whereNull('revoked_at')
            ->when($except !== null, fn ($q) => $q->where(fn ($q) => $q->whereNull('access_token_id')->orWhere('access_token_id', '!=', $except)))
            ->update(['revoked_at' => now()]);
        $owner->tokens()->when($except !== null, fn ($q) => $q->where('id', '!=', $except))->delete();
    }

    public function revokeOne($owner, int $tokenId): void
    {
        DB::table($this->table($owner))->where('user_id', $owner->id)->where('access_token_id', $tokenId)->update(['revoked_at' => now()]);
        $owner->tokens()->whereKey($tokenId)->delete();
    }
}
