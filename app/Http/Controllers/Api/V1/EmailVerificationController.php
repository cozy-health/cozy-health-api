<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller;
use App\Http\Requests\EmailVerificationRequest;
use App\Mail\VerifyEmailMail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

class EmailVerificationController extends Controller
{
    public function resend(Request $request)
    {
        $user = $request->user();
        if ($user->email_verified_at) { return response()->json(['message' => 'Your email is already verified.']); }
        abort_if($user->email_invalid, 422, 'Please update your email address before requesting a code.');
        $code = (string) random_int(100000, 999999);
        DB::table('email_verification_codes')->updateOrInsert(['user_id' => $user->id], ['code_hash' => Hash::make($code), 'expires_at' => now()->addMinutes(15)]);
        Mail::to($user->email)->send(new VerifyEmailMail($code));
        return response()->json(['message' => 'A verification code is on its way.']);
    }

    public function verify(EmailVerificationRequest $request)
    {
        DB::transaction(function () use ($request) {
            $record = DB::table('email_verification_codes')->where('user_id', $request->user()->id)->lockForUpdate()->first();
            abort_unless($record && now()->lt($record->expires_at) && Hash::check($request->validated('code'), $record->code_hash), 422, 'That code is invalid or expired. Please request a new one.');
            $request->user()->forceFill(['email_verified_at' => now()])->save();
            DB::table('email_verification_codes')->where('user_id', $request->user()->id)->delete();
        });
        return response()->json(['message' => 'Your email is verified.']);
    }
}
