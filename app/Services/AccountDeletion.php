<?php

namespace App\Services;

use App\Mail\AccountDeletedMail;
use App\Models\User;
use App\Services\Apple\AppleTokenService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class AccountDeletion
{
    public function delete(User $user, AppleTokenService $appleTokens, ?string $identityFingerprint = null, ?int $privacyActor = null, ?string $privacyRequestId = null): void
    {

        if ($user->is_demo) {
            abort(403, 'This account cannot be deleted.');
        }
        // Provider calls stay outside the database transaction.
        $appleTokens->revoke($user);

        $ledger = app(DeletionLedger::class);
        $operation = $ledger->enabled() ? (string) Str::uuid() : null;
        $recorded = false;
        try {
            $this->erase($user, $identityFingerprint, $privacyActor, $privacyRequestId, function (User $locked) use ($ledger, $operation, &$recorded) {
                if ($operation !== null) {
                    $ledger->begin($locked, $operation);
                    $recorded = true;
                }
            });
        } catch (\Throwable $error) {
            if ($recorded) {
                try { $ledger->finish($operation, 'aborted'); }
                catch (\Throwable) { Log::warning('Deletion receipt remains unresolved after database rollback.'); }
            }
            throw $error;
        }
        // A failed completion write leaves a pending record and never reports success.
        if ($operation !== null) $ledger->finish($operation, 'completed');

        if (! $user->email_invalid) {
            try {
                Mail::to($user->email)->send(new AccountDeletedMail);
            } catch (\Throwable) {
                Log::warning('Account deletion confirmation could not be queued.');
            }
        }
    }

    /** Restore-only cleanup: no provider requests, confirmation mail or new receipt. */
    public function replay(User $user): void
    {
        app(DeletionLedger::class)->assertAvailable();
        $this->erase($user);
    }

    private function erase(User $user, ?string $identityFingerprint = null, ?int $privacyActor = null, ?string $privacyRequestId = null, ?callable $beforeErase = null): void
    {
        app(CustomerContext::class)->run($user, fn () => DB::transaction(function () use ($user, $identityFingerprint, $privacyActor, $privacyRequestId, $beforeErase) {
            $user = User::withTrashed()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            if ($identityFingerprint !== null) {
                abort_unless($user->is_active && $user->email_verified_at && hash_equals($identityFingerprint, app(CustomerPrivacy::class)->fingerprint($user)), 409, 'Customer identity changed. A new verified request is required.');
            }
            if ($privacyActor !== null && $privacyRequestId !== null) {
                AdminAudit::record($privacyActor, 'delete-account', 'privacy-requests', $privacyRequestId);
            }
            if ($beforeErase !== null) $beforeErase($user);
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
        }, 3));
    }
}
