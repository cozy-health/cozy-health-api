<?php

namespace App\Http\Controllers;

use App\Models\Provider;
use App\Models\User;
use App\Services\AuthSessions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AuthSessionController extends Controller
{
    public function refresh(Request $request, AuthSessions $sessions)
    {
        $data = $request->validate(['refresh_token' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]+$/']]);
        $provider = $request->is('api/v1/provider/*');
        return DB::transaction(function () use ($data, $request, $provider, $sessions) {
            $table = $provider ? 'provider_refresh_tokens' : 'refresh_tokens';
            $row = DB::table($table)->where('token_hash', hash('sha256', $data['refresh_token']))->lockForUpdate()->first();
            abort_unless($row && ! $row->revoked_at && now()->lt($row->expires_at), 401, 'Refresh token expired or revoked.');
            $owner = ($provider ? Provider::class : User::class)::whereKey($row->user_id)->lockForUpdate()->first();
            abort_unless($owner && $owner->is_active && ($provider || $owner->role === 'user'), 401, 'Session is unavailable.');
            DB::table($table)->where('id', $row->id)->update(['revoked_at' => now()]);
            $owner->tokens()->whereKey($row->access_token_id)->delete();
            return response()->json(['success' => true, 'data' => $sessions->issue($owner, $request, $row->device_name, false)])
                ->header('Cache-Control', 'private, no-store');
        }, 3);
    }

    public function revokeRefresh(Request $request, AuthSessions $sessions)
    {
        $data = $request->validate(['refresh_token' => ['required', 'string', 'size:64']]);
        DB::table($sessions->table($request->user()))->where('user_id', $request->user()->id)
            ->where('token_hash', hash('sha256', $data['refresh_token']))->update(['revoked_at' => now()]);
        return response()->json(['success' => true]);
    }

    public function index(Request $request)
    {
        $current = $request->user()->currentAccessToken()?->id;
        $rows = $request->user()->tokens()->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->get()
            ->map(fn ($token) => ['id' => $token->id, 'device' => $token->name, 'ip' => $token->ip_address,
                'last_used' => $token->last_used_at?->toIso8601String() ?? $token->created_at?->toIso8601String(), 'is_current' => $token->id === $current]);
        return response()->json(['success' => true, 'data' => $rows])->header('Cache-Control', 'private, no-store');
    }

    public function destroy(Request $request, string $id, AuthSessions $sessions)
    {
        $token = $request->user()->tokens()->whereKey($id)->firstOrFail();
        DB::transaction(fn () => $sessions->revokeOne($request->user(), $token->id));
        return response()->json(['success' => true]);
    }

    public function destroyAll(Request $request, AuthSessions $sessions)
    {
        DB::transaction(fn () => $sessions->revoke($request->user()));
        return response()->json(['success' => true]);
    }

    public function password(Request $request, AuthSessions $sessions)
    {
        $data = $request->validate(['current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', new \App\Rules\StrongPassword]]);
        DB::transaction(function () use ($request, $data, $sessions) {
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            abort_unless(Hash::check($data['current_password'], $user->password), 422, 'Current password was not accepted.');
            $user->update(['password' => $data['password']]);
            $sessions->revoke($user, $request->user()->currentAccessToken()?->id);
        });
        return response()->json(['success' => true, 'message' => 'Password changed. Other devices signed out.']);
    }
}
