<?php

namespace App\Http\Controllers;

use App\Models\Provider;
use App\Services\SignInSecurity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class SignInSecurityController extends Controller
{
    private function enabled(Request $request, SignInSecurity $security): void
    {
        $owner = $request->user();
        abort_unless(config('signin_security.enabled') || ($owner instanceof \App\Models\User || $owner instanceof Provider) && $security->requiresProtection($owner), 503, 'Sign-in protection setup is not available in this build yet.');
    }

    private function role(Request $request): string
    {
        return $request->is('api/v1/provider/*') ? 'provider' : 'user';
    }

    public function verify(Request $request, SignInSecurity $security)
    {
        $data = $request->validate(['challenge_token' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]+$/'],
            'code' => ['sometimes', 'string', 'size:6'], 'answers' => ['sometimes', 'array', 'max:2'],
            'answers.*' => ['string', 'max:200']]);
        abort_unless(config('signin_security.enabled') || $security->hasChallenge($this->role($request), $data['challenge_token']), 503, 'Sign-in protection setup is not available in this build yet.');

        return response()->json(['success' => true, 'data' => $security->verify($this->role($request), $data['challenge_token'], $data, $request)])
            ->header('Cache-Control', 'private, no-store');
    }

    public function resend(Request $request, SignInSecurity $security)
    {
        $data = $request->validate(['challenge_token' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]+$/']]);
        abort_unless(config('signin_security.enabled') || $security->hasChallenge($this->role($request), $data['challenge_token']), 503, 'Sign-in protection setup is not available in this build yet.');

        return response()->json(['success' => true, 'data' => $security->resend($this->role($request), $data['challenge_token'])])
            ->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, SignInSecurity $security)
    {
        return response()->json(['data' => $security->preferences($request->user())])->header('Cache-Control', 'private, no-store');
    }

    public function reauthenticate(Request $request, SignInSecurity $security)
    {
        $this->enabled($request, $security);
        $data = $request->validate(['password' => ['required', 'string', 'max:255']]);
        $owner = $request->user();
        abort_unless(Hash::check($data['password'], $owner instanceof Provider ? $owner->password_hash : $owner->password), 422, 'The password was not accepted.');

        return response()->json(['data' => $security->begin($owner, $request, 'security-settings', 'settings')])->header('Cache-Control', 'private, no-store');
    }

    public function update(Request $request, SignInSecurity $security)
    {
        $this->enabled($request, $security);

        return response()->json(['data' => $security->update($request->user(), $request->all())])->header('Cache-Control', 'private, no-store');
    }
}
