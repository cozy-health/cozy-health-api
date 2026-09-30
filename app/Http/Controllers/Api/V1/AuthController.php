<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\{LoginRequest, RegisterRequest};
use App\Mail\WelcomeMail;
use App\Models\User;
use App\Support\ApiResponse;
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

    public function logout()
    {
        request()->user()->currentAccessToken()?->delete();
        
        return $this->success(null, 'Logged out successfully.');
    }
}
