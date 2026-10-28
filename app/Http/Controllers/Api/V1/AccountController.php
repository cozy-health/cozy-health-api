<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Mail\AccountDeletedMail;
use App\Models\User;
use App\Services\Apple\AppleTokenService;
use App\Services\AuthSessions;
use App\Services\CommunityPrivacy;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class AccountController extends Controller
{
    use ApiResponse;

    public function destroy(Request $request, AppleTokenService $appleTokens)
    {
        $user = $request->user();
        if ($user->is_demo) {
            return response()->json(['success' => false, 'message' => 'This account cannot be deleted.'], 403);
        }
        // Provider calls stay outside the database transaction.
        $appleTokens->revoke($user);

        DB::transaction(function () use ($user) {
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            app(AuthSessions::class)->revoke($user);
            DB::table('known_devices')->where('owner_type', $user->getMorphClass())->where('owner_id', $user->id)->delete();
            foreach (['signin_challenges', 'signin_security_preferences'] as $table) {
                DB::table($table)->where('owner_type', $user->getMorphClass())->where('owner_id', $user->id)->delete();
            }
            DB::table('password_reset_tokens')->where('email', $user->email)->delete();
            DB::table('notifications')->where('notifiable_type', $user->getMorphClass())
                ->where('notifiable_id', $user->id)->delete();
            $user->forceFill([
                'google_sub' => null,
                'apple_sub' => null,
                'google_linked_at' => null,
                'apple_linked_at' => null,
                'apple_refresh_token' => null,
                'apple_token_client_id' => null,
            ])->save();
            // User-owned rows with foreign keys are removed by their existing cascades.
            app(CommunityPrivacy::class)->removeAccount($user->id);
            $user->forceDelete();
        }, 3);

        if (! $user->email_invalid) {
            try {
                Mail::to($user->email)->send(new AccountDeletedMail);
            } catch (\Throwable) {
                Log::warning('Account deletion confirmation could not be queued.');
            }
        }

        return $this->success(null, 'Account deleted successfully.');
    }
}
