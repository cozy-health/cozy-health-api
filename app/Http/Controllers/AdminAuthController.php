<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AdminAuthController extends Controller
{
    public function login(Request $request)
    {
        if (is_string($request->input('email'))) $request->merge(['email' => strtolower(trim($request->input('email')))]);
        $data = $request->validate(['email' => ['required', 'email', 'max:190'], 'password' => ['required', 'string', 'max:255']]);
        $user = User::where('email', $data['email'])->first();
        if (! $user || ! Hash::check($data['password'], $user->password) || $user->role !== 'admin' || ! $user->is_active || $user->is_demo) {
            throw ValidationException::withMessages(['email' => ['The provided credentials are incorrect.']]);
        }
        $expires = now()->addMinutes(30);
        $management = $request->input('session_mode') === 'management-v1';
        $abilities = $management ? ['admin:metrics', 'admin:users', 'admin:content', 'admin:moderation', 'admin:subscriptions', 'admin:email', ...(in_array($user->id, array_map('intval', array_filter(explode(',', (string) config('age.reviewers')))), true) ? ['admin:guardians'] : [])] : ['admin:metrics'];
        return response()->json(['token' => $user->createToken($management ? 'cozy-admin-management-v1' : 'cozy-admin-dashboard', $abilities, $expires)->plainTextToken,
            'expires_at' => $expires->toIso8601String()])->header('Cache-Control', 'private, no-store');
    }

    public function session(Request $request)
    {
        $management = $request->user()->currentAccessToken()->name === 'cozy-admin-management-v1';
        return response()->json(['role' => 'admin', ...($management ? ['name' => $request->user()->name, 'capabilities' => $request->user()->currentAccessToken()->abilities] : [])])->header('Cache-Control', 'private, no-store');
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Signed out.'])->header('Cache-Control', 'private, no-store');
    }
}
