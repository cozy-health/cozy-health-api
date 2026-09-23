<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Carbon\Carbon;
use App\Mail\PasswordResetRequestMail;
use App\Mail\PasswordResetConfirmationMail;

class PasswordResetController extends Controller
{
    use ApiResponse;

    public function forgotPassword(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        $user = User::where('email', $request->email)->first();

        if ($user) {
            // Generate a 6-digit code
            $token = sprintf('%06d', mt_rand(100000, 999999));
            
            // Upsert token in database
            DB::table('password_reset_tokens')->updateOrInsert(
                ['email' => $user->email],
                [
                    'token' => Hash::make($token), // Store hashed in DB for security
                    'created_at' => Carbon::now(),
                    'expires_at' => Carbon::now()->addMinutes(15)
                ]
            );

            // Send email (send the plain text token)
            Mail::to($user->email)->send(new PasswordResetRequestMail($user, $token));
        }

        // Always return success even if user doesn't exist
        return $this->success(null, 'If your email is registered, you will receive a password reset code shortly.');
    }

    public function resetPassword(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'token' => 'required|string',
            'password' => 'required|string|min:8',
        ]);

        $resetRecord = DB::table('password_reset_tokens')
            ->where('email', $request->email)
            ->first();

        if (!$resetRecord || !Hash::check($request->token, $resetRecord->token)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid or incorrect reset code.'
            ], 400);
        }

        if (Carbon::parse($resetRecord->expires_at)->isPast()) {
            return response()->json([
                'success' => false,
                'message' => 'This reset code has expired. Please request a new one.'
            ], 400);
        }

        $user = User::where('email', $request->email)->first();
        
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found.'
            ], 404);
        }

        // Update password
        $user->password = Hash::make($request->password);
        $user->save();

        // Delete the token
        DB::table('password_reset_tokens')->where('email', $request->email)->delete();

        // Send confirmation email
        Mail::to($user->email)->send(new PasswordResetConfirmationMail($user));

        return $this->success(null, 'Your password has been successfully reset.');
    }
}
