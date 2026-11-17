<?php

namespace App\Jobs;

use App\Models\PrivacyRequest;
use App\Models\User;
use App\Services\AccountDeletion;
use App\Services\Apple\AppleTokenService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;

class ProcessPrivacyDeletion implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(public string $requestId, public int $version, public int $actorId) {}

    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(AccountDeletion $deletion, AppleTokenService $apple): void
    {
        $record = PrivacyRequest::find($this->requestId);
        if (! $record || $record->status !== 'processing' || (int) $record->version !== $this->version) {
            return;
        }
        $actor = User::find($this->actorId);
        abort_unless($actor?->is_active && $actor->role === 'admin' && in_array($actor->id, config('operations.privacy_reviewers', []), true), 403, 'The privacy reviewer assignment is no longer active.');
        $user = User::findOrFail($record->user_id);
        // Provider revocation is outside transactions; account/file cleanup is shared.
        $deletion->delete($user, $apple, $record->identity_fingerprint, $actor->id, $record->id);
    }

    public function failed(?\Throwable $error = null): void
    {
        $record = PrivacyRequest::find($this->requestId);
        if ($record && $record->status === 'processing' && (int) $record->version === $this->version) {
            $record->update(['status' => 'failed', 'version' => $record->version + 1,
                'outcome' => 'Deletion did not finish. The privacy team can review and retry; account removal is not confirmed.']);
        }
    }
}
