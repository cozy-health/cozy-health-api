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
        return response()->json(['token' => $user->createToken('cozy-admin-dashboard', ['admin:metrics'], $expires)->plainTextToken,
            'expires_at' => $expires->toIso8601String()])->header('Cache-Control', 'private, no-store');
    }

    public function session()
    {
        return response()->json(['role' => 'admin'])->header('Cache-Control', 'private, no-store');
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Signed out.'])->header('Cache-Control', 'private, no-store');
    }
}
