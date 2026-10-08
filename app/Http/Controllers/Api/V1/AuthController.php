<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\{LoginRequest, RegisterRequest};
use App\Mail\WelcomeMail;
use App\Models\User;
use App\Services\Apple\AppleTokenVerifier;
use App\Services\Apple\AppleTokenService;
use App\Services\Apple\InvalidAppleTokenException;
use App\Services\Google\GoogleTokenVerifier;
use App\Services\Google\InvalidGoogleTokenException;
use App\Support\ApiResponse;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    use ApiResponse;

    public function register(RegisterRequest $r)
    {
        $u = User::create($r->validated());

        $u->profile()->create([
            'id' => (string) Str::uuid(),
            'name' => $u->name,
            'email' => $u->email,
            'username' => null,
            'avatar_url' => null,
            'bio' => null,
            'theme' => 'system',
            'accent_color' => '#0460D8',
            'language' => 'en',
            'reduce_motion' => false,
            'high_contrast' => false,
            'haptics_enabled' => true,
            'text_size' => 1.00,
            'show_stats' => false,
            'show_username' => true,
            'notifications_master' => true,
            'daily_checkin_enabled' => true,
            'daily_checkin_time' => '09:00',
            'journal_reminder_enabled' => false,
            'comments_notifications' => true,
            'achievements_notifications' => true,
            'marketing_notifications' => false,
        ]);

        try {
            Mail::to($u->email)->send(new WelcomeMail($u));
            \Log::info('WELCOME EMAIL SENT to ' . $u->email);
        } catch (\Throwable $e) {
            \Log::error('WELCOME EMAIL FAILED: ' . $e->getMessage());
        }

        $token = $u->createToken($r->input('device_name', 'cozy-mobile'))->plainTextToken;

        return $this->success([
            'user' => $u,
            'token' => $token,
        ], 'Account created successfully.', 201);
    }

    public function login(LoginRequest $r)
    {
        $u = User::where('email', $r->email)->first();

        if (!$u || !Hash::check($r->password, $u->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if (!$u->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'This account has been disabled.',
            ], 403);
        }

        $token = $u->createToken($r->input('device_name', 'cozy-mobile'))->plainTextToken;

        return $this->success([
            'user' => $u,
            'token' => $token,
        ], 'Logged in successfully.');
    }

    public function googleSignIn(Request $request, GoogleTokenVerifier $googleVerifier)
    {
        $request->validate([
            'id_token' => ['required', 'string', 'max:16384'],
            'device_name' => ['sometimes', 'string', 'max:120'],
        ]);

        try {
            $claims = $googleVerifier->verify($request->input('id_token'));
        } catch (InvalidGoogleTokenException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid Google ID token.',
            ], 401);
        }

        $created = false;
        try {
            $user = DB::transaction(function () use ($claims, &$created) {
                $user = User::where('google_sub', $claims['sub'])->lockForUpdate()->first();

                if (!$user) {
                    $user = User::where('email', $claims['email'])->lockForUpdate()->first();
                    if ($user) {
                        abort_if(!$user->is_active, 403, 'This account has been disabled.');
                        abort_if(!$claims['email_verified'], 409, 'Sign in to your existing account to link Google.');
                        abort_if($user->google_sub !== null, 409, 'This account is already linked to a different Google identity.');
                        $user->google_sub = $claims['sub'];
                        $user->google_linked_at = now();
                        if ($user->email_verified_at === null) {
                            $user->email_verified_at = now();
                        }
                        $user->save();
                    }
                }

                if (!$user) {
                    $user = new User([
                        'name' => $claims['name'] ?? 'Cozy User',
                        'email' => $claims['email'],
                        'google_sub' => $claims['sub'],
                        'google_linked_at' => now(),
                        'password' => Hash::make(Str::random(64)),
                        'is_active' => true,
                    ]);
                    // email_verified_at is deliberately not mass assignable on User.
                    $user->email_verified_at = $claims['email_verified'] ? now() : null;
                    $user->save();
                    $user->profile()->create([
                        'id' => (string) Str::uuid(),
                        'name' => $user->name,
                        'email' => $user->email,
                        'username' => null,
                        'avatar_url' => null,
                        'bio' => null,
                        'theme' => 'system',
                        'accent_color' => '#0460D8',
                        'language' => 'en',
                        'reduce_motion' => false,
                        'high_contrast' => false,
                        'haptics_enabled' => true,
                        'text_size' => 1.00,
                        'show_stats' => false,
                        'show_username' => true,
                        'notifications_master' => true,
                        'daily_checkin_enabled' => true,
                        'daily_checkin_time' => '09:00',
                        'journal_reminder_enabled' => false,
                        'comments_notifications' => true,
                        'achievements_notifications' => true,
                        'marketing_notifications' => false,
                    ]);
                    $created = true;
                }

                abort_if(!$user->is_active, 403, 'This account has been disabled.');

                return $user->refresh();
            }, 3);
        } catch (UniqueConstraintViolationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'An account was linked concurrently. Please try again.',
            ], 409);
        }

        if ($created) {
            try {
                Mail::to($user->email)->send(new WelcomeMail($user));
            } catch (\Throwable $e) {
                \Log::error('WELCOME EMAIL FAILED: ' . $e->getMessage());
            }
        }

        $token = $user->createToken($request->input('device_name', 'cozy-mobile'))->plainTextToken;

        return $this->success([
            'user' => $user,
            'token' => $token,
        ], 'Logged in successfully.');
    }

    public function appleSignIn(Request $request, AppleTokenVerifier $appleVerifier, AppleTokenService $appleTokens)
    {
        $request->validate([
            'identity_token' => ['required', 'string', 'max:16384'],
            'authorization_code' => ['sometimes', 'string', 'max:4096'],
            'full_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'device_name' => ['sometimes', 'string', 'max:120'],
        ]);

        try {
            $claims = $appleVerifier->verify($request->input('identity_token'));
            $grant = $request->filled('authorization_code')
                ? $appleTokens->exchangeAuthorizationCode($request->input('authorization_code'), $claims) : null;
        } catch (InvalidAppleTokenException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid Apple identity token.',
            ], 401);
        }

        $created = false;
        try {
            $user = DB::transaction(function () use ($request, $claims, $grant, &$created) {
                $user = User::where('apple_sub', $claims['sub'])->lockForUpdate()->first();

                if (!$user) {
                    $emailUser = User::where('email', $claims['email'])->lockForUpdate()->first();
                    if ($emailUser) {
                        abort_if(!$emailUser->is_active, 403, 'This account has been disabled.');
                        $privateRelay = $claims['is_private_email']
                            || str_ends_with(strtolower($claims['email']), '@privaterelay.appleid.com');
                        abort_if($privateRelay || !$claims['email_verified'], 409, 'Sign in to your existing account to link Apple.');
                        abort_if($emailUser->apple_sub !== null, 409, 'This account is already linked to a different Apple identity.');
                        $user = $emailUser;
                        $user->apple_sub = $claims['sub'];
                        $user->apple_linked_at = now();
                        if ($user->email_verified_at === null) {
                            $user->email_verified_at = now();
                        }
                        $user->save();
                    }
                }

                if (!$user) {
                    $fullName = trim($request->input('full_name') ?? '');
                    $user = new User([
                        'name' => $fullName !== '' ? $fullName : 'Cozy User',
                        'email' => $claims['email'],
                        'apple_sub' => $claims['sub'],
                        'apple_linked_at' => now(),
                        'password' => Hash::make(Str::random(64)),
                        'is_active' => true,
                    ]);
                    // email_verified_at is deliberately not mass assignable on User.
                    $user->email_verified_at = $claims['email_verified'] ? now() : null;
                    $user->save();
                    $user->profile()->create([
                        'id' => (string) Str::uuid(),
                        'name' => $user->name,
                        'email' => $user->email,
                        'username' => null,
                        'avatar_url' => null,
                        'bio' => null,
                        'theme' => 'system',
                        'accent_color' => '#0460D8',
                        'language' => 'en',
                        'reduce_motion' => false,
                        'high_contrast' => false,
                        'haptics_enabled' => true,
                        'text_size' => 1.00,
                        'show_stats' => false,
                        'show_username' => true,
                        'notifications_master' => true,
                        'daily_checkin_enabled' => true,
                        'daily_checkin_time' => '09:00',
                        'journal_reminder_enabled' => false,
                        'comments_notifications' => true,
                        'achievements_notifications' => true,
                        'marketing_notifications' => false,
                    ]);
                    $created = true;
                }

                abort_if(!$user->is_active, 403, 'This account has been disabled.');

                if ($grant !== null) {
                    $user->apple_refresh_token = $grant['refresh_token'];
                    $user->apple_token_client_id = $grant['client_id'];
                    $user->save();
                }

                return $user->refresh();
            }, 3);
        } catch (UniqueConstraintViolationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'An account was linked concurrently. Please try again.',
            ], 409);
        }

        if ($created) {
            try {
                Mail::to($user->email)->send(new WelcomeMail($user));
            } catch (\Throwable $e) {
                \Log::error('WELCOME EMAIL FAILED: ' . $e->getMessage());
            }
        }

        $token = $user->createToken($request->input('device_name', 'cozy-mobile'))->plainTextToken;

        return $this->success([
            'user' => $user,
            'token' => $token,
        ], 'Logged in successfully.');
    }

    public function logout()
    {
        request()->user()->currentAccessToken()?->delete();
        
        return $this->success(null, 'Logged out successfully.');
    }
}
