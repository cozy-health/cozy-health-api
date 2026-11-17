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

    public function forgotPassword(\App\Http\Requests\ForgotPasswordRequest $request)
    {
        $request->validated();

        $user = User::where('email', $request->email)->first();

        if ($user && $user->is_active && ! $user->email_invalid) {
            // Generate a 6-digit code
            $token = (string) random_int(100000, 999999);

            // Upsert token in database
            DB::table('password_reset_tokens')->updateOrInsert(
                ['email' => $user->email],
                [
                    'token' => Hash::make($token), // Store hashed in DB for security
                    'created_at' => Carbon::now(),
                    'expires_at' => Carbon::now()->addMinutes(15),
                    'failed_attempts' => 0,
                ]
            );

            // Send email (send the plain text token)
            Mail::to($user->email)->send(new PasswordResetRequestMail($user, $token));
        }

        // Always return success even if user doesn't exist
        return $this->success(null, 'If your email is registered, you will receive a password reset code shortly.');
    }

    public function resetPassword(\App\Http\Requests\ResetPasswordRequest $request)
    {
        $request->validated();

        $result = DB::transaction(function () use ($request) {
            $record = DB::table('password_reset_tokens')->where('email', $request->email)->lockForUpdate()->first();
            if (! $record || $record->failed_attempts >= 5) {
                return ['status' => 400, 'message' => 'Invalid or incorrect reset code.'];
            }
            if (now()->gte($record->expires_at)) {
                return ['status' => 400, 'message' => 'This reset code has expired. Please request a new one.'];
            }
            if (! Hash::check($request->token, $record->token)) {
                DB::table('password_reset_tokens')->where('email', $request->email)->increment('failed_attempts');
                return ['status' => 400, 'message' => 'Invalid or incorrect reset code.'];
            }
            $user = User::where('email', $request->email)->lockForUpdate()->first();
            if (! $user || ! $user->is_active) { return ['status' => 400, 'message' => 'Invalid or incorrect reset code.']; }
            $user->update(['password' => $request->password]);
            app(\App\Services\AuthSessions::class)->revoke($user);
            DB::table('password_reset_tokens')->where('email', $user->email)->delete();
            return ['user' => $user];
        }, 3);
        if (isset($result['status'])) {
            return response()->json(['success' => false, 'message' => $result['message']], $result['status']);
        }
        $user = $result['user'];

        // Send confirmation email
        try {
            Mail::to($user->email)->send(new PasswordResetConfirmationMail($user));
        } catch (\Throwable) {
            \Log::warning('Password reset confirmation could not be queued.');
            return $this->success(null, 'Your password has been successfully reset. The confirmation email could not be queued.');
        }

        return $this->success(null, 'Your password has been successfully reset.');
    }
}
