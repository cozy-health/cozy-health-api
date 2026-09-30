<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\{LoginRequest, RegisterRequest};
use App\Mail\WelcomeMail;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    use ApiResponse;

    public function register(RegisterRequest $r)
    {
        Log::info('REGISTER CALLED', ['email' => $r->input('email')]);

        $u = User::create($r->validated());

        Log::info('USER CREATED', ['id' => $u->id, 'email' => $u->email]);

        // Send welcome email - never let it break registration
        try {
            Log::info('ABOUT TO SEND WELCOME EMAIL', [
                'to' => $u->email,
                'mailer' => config('mail.default'),
                'host' => config('mail.mailers.smtp.host'),
                'port' => config('mail.mailers.smtp.port'),
                'username_set' => !empty(config('mail.mailers.smtp.username')),
                'from' => config('mail.from.address'),
            ]);

            Mail::to($u->email)->send(new WelcomeMail($u));

            Log::info('WELCOME EMAIL SENT', ['email' => $u->email]);
        } catch (\Throwable $e) {
            Log::error('WELCOME EMAIL FAILED', [
                'error' => $e->getMessage(),
                'class' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
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
