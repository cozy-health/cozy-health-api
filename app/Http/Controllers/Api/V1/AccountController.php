<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Apple\AppleTokenService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
            $user->tokens()->delete();
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
            $user->forceDelete();
        }, 3);

        return $this->success(null, 'Account deleted successfully.');
    }
}
