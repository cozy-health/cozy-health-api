<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Models\User;
use App\Support\ApiResponse;
use App\Mail\WelcomeMail;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    use ApiResponse;

    public function register(RegisterRequest $request)
    {
        $user = User::create($request->validated());
        
        // Send welcome email in background
        try {
            Mail::to($user->email)->send(new WelcomeMail($user));
            \Log::info('Welcome email sent', [
                'email' => $user->email,
            ]);
        } catch (\Throwable $e) {
            \Log::error('Welcome email failed', [
                'user_id' => $user->id,
                'email' => $user->email,
                'error' => $e->getMessage(),
            ]);
        }

        $token = $user->createToken($request->input('device_name', 'cozy-mobile'))->plainTextToken;
        
        return $this->success([
            'user' => $user,
            'token' => $token
        ], 'Account created successfully.', 201);
    }

    public function login(LoginRequest $request)
    {
        $user = User::where('email', $request->email)->first();
        
        if (!$user || !Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.']
            ]);
        }
        
        if (!$user->is_active) {
            return response()->json([
                'success' => false, 
                'message' => 'This account has been disabled.'
            ], 403);
        }
        
        $token = $user->createToken($request->input('device_name', 'cozy-mobile'))->plainTextToken;
        
        return $this->success([
            'user' => $user,
            'token' => $token
        ], 'Logged in successfully.');
    }

    public function logout()
    {
        request()->user()->currentAccessToken()?->delete();
        
        return $this->success(null, 'Logged out successfully.');
    }
}
