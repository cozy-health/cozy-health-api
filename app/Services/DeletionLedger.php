<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

final class DeletionLedger
{
    public function enabled(): bool
    {
        return (bool) config('deletion-ledger.enabled');
    }

    public function assertAvailable(): void
    {
        if (! $this->enabled()) {
            throw new RuntimeException('Deletion ledger requires explicit activation.');
        }
        app(BackupEnvironment::class)->assertApproved();
        if (! app()->environment('testing') && config('filesystems.disks.'.config('deletion-ledger.disk').'.driver') !== 's3') {
            throw new RuntimeException('Deletion records must be outside the database volume in private object storage.');
        }
        if (blank(config('deletion-ledger.activated_at')) || Carbon::parse(config('deletion-ledger.activated_at'))->greaterThan(now())) {
            throw new RuntimeException('Record the actual ledger activation timestamp before enabling it.');
        }
    }

    public function begin(User $user, string $operation): void
    {
        $this->assertAvailable();
        $this->write([
            'schema' => 1, 'operation_id' => $operation, 'state' => 'pending',
            'account_id' => $user->id, 'account_created_at' => $user->getRawOriginal('created_at'),
            'requested_at' => now()->toIso8601String(),
            'expires_at' => now()->addDays(config('deletion-ledger.retention_days'))->toIso8601String(),
            'files' => $user->privateDeletionFiles(),
        ]);
    }

    public function finish(string $operation, string $state): void
    {
        $this->assertAvailable();
        if (! in_array($state, ['completed', 'aborted'], true)) throw new RuntimeException('Invalid deletion outcome.');
        $record = $this->read($this->path($operation));
        if ($record['state'] !== 'pending') throw new RuntimeException('Deletion outcome has already been recorded.');
        $record['state'] = $state;
        $this->write($record);
    }

    private function disk(): Filesystem
    {
        return Storage::disk(config('deletion-ledger.disk'));
    }

    private function path(string $operation): string
    {
        if (! Str::isUuid($operation)) throw new RuntimeException('Invalid deletion operation.');
        return config('deletion-ledger.prefix').'/'.$operation.'.json.enc';
    }

    private function write(array $record): void
    {
        $path = $this->path($record['operation_id']);
        $this->validate($record, $path);
        if (! $this->disk()->put($path, Crypt::encryptString(json_encode($record, JSON_THROW_ON_ERROR)), ['visibility' => 'private'])) {
            throw new RuntimeException('Deletion record could not be stored.');
        }
    }

    private function read(string $path): array
    {
        $content = $this->disk()->get($path);
        if (! is_string($content)) throw new RuntimeException('Deletion record is unavailable.');
        $record = json_decode(Crypt::decryptString($content), true, 32, JSON_THROW_ON_ERROR);
        $this->validate($record, $path);
        return $record;
    }

    private function validate(mixed $record, string $path): void
    {
        if (! is_array($record) || ($record['schema'] ?? null) !== 1
            || ! is_string($record['operation_id'] ?? null) || $this->path($record['operation_id']) !== $path
            || ! in_array($record['state'] ?? null, ['pending', 'completed', 'aborted'], true)
            || ! is_int($record['account_id'] ?? null) || $record['account_id'] < 1
            || ! is_string($record['account_created_at'] ?? null) || $record['account_created_at'] === ''
            || ! is_string($record['requested_at'] ?? null) || ! is_string($record['expires_at'] ?? null)
            || ! is_array($record['files'] ?? null)) throw new RuntimeException('Invalid deletion record.');
        $requested = Carbon::parse($record['requested_at']);
        $expiry = Carbon::parse($record['expires_at']);
        if ($expiry->lessThanOrEqualTo($requested) || $requested->greaterThan(now())) throw new RuntimeException('Invalid deletion dates.');
        foreach ($record['files'] as $file) {
            if (! is_array($file) || ! in_array($file['disk'] ?? null, array_unique(['local', config('avatars.disk')]), true)
                || ! is_string($file['key'] ?? null) || $file['key'] === '' || str_starts_with($file['key'], '/')
                || str_contains($file['key'], '\\') || in_array('..', explode('/', $file['key']), true)
                || str_starts_with($file['key'], 'deletion-ledger/')) throw new RuntimeException('Invalid private-file reference.');
        }
    }

    private function records(): array
    {
        $this->assertAvailable();
        $records = [];
        foreach ($this->disk()->files(config('deletion-ledger.prefix')) as $path) {
            $records[] = $this->read($path);
        }
        return $records;
    }

    public function replay(Carbon $snapshot, bool $execute = false): int
    {
        $this->assertAvailable();
        if ($snapshot->greaterThan(now()) || $snapshot->lessThan(now()->subDays(config('deletion-ledger.backup_days')))) {
            throw new RuntimeException('Replay requires a snapshot within the approved seven-day backup window.');
        }
        if ($snapshot->lessThan(Carbon::parse(config('deletion-ledger.activated_at')))) {
            throw new RuntimeException('This snapshot predates deletion-ledger coverage and requires separate reconciliation.');
        }
        // Validate the complete set before touching any account or cleanup queue.
        $records = $this->records();
        foreach ($records as $record) {
            if ($record['state'] === 'pending') throw new RuntimeException('Unresolved deletion record: reconcile it against the original database before reopening a restore.');
        }
        $records = array_filter($records, fn ($r) => $r['state'] === 'completed' && Carbon::parse($r['requested_at'])->greaterThanOrEqualTo($snapshot));
        foreach ($records as $record) {
            $this->assertIdentity($record, User::withTrashed()->find($record['account_id']));
        }
        if ($execute) {
            foreach ($records as $record) {
                DB::transaction(function () use ($record) {
                    $user = User::withTrashed()->whereKey($record['account_id'])->lockForUpdate()->first();
                    $this->assertIdentity($record, $user);
                    if ($user) app(AccountDeletion::class)->replay($user);
                    foreach ($record['files'] as $file) {
                        if (! DB::table('avatar_photo_deletions')->where('disk', $file['disk'])->where('object_key', $file['key'])->exists()) {
                            ProfileAvatarService::queueDeletion($file['disk'], $file['key']);
                        }
                    }
                });
            }
        }
        return count($records);
    }

    private function assertIdentity(array $record, ?User $user): void
    {
        if ($user && ($user->is_demo || $user->getRawOriginal('created_at') !== $record['account_created_at'])) {
            throw new RuntimeException('Restored account identity does not match the deletion record.');
        }
    }

    public function prune(bool $execute = false): int
    {
        $records = $this->records();
        if (array_filter($records, fn ($r) => $r['state'] === 'pending' && Carbon::parse($r['expires_at'])->lessThanOrEqualTo(now()))) {
            Log::warning('Expired unresolved deletion receipts require original-database reconciliation; they have been retained.');
        }
        $expired = array_filter($records, fn ($r) => $r['state'] !== 'pending' && Carbon::parse($r['expires_at'])->lessThanOrEqualTo(now()));
        if ($execute) foreach ($expired as $record) {
            if (! $this->disk()->delete($this->path($record['operation_id']))) throw new RuntimeException('Expired deletion record could not be removed.');
        }
        return count($expired);
    }

    /** Operator must attest this is the original database, never an old restore. */
    public function reconcile(string $operation, string $outcome, bool $execute = false): void
    {
        $this->assertAvailable();
        if (! in_array($outcome, ['completed', 'aborted'], true)) throw new RuntimeException('Invalid deletion outcome.');
        DB::transaction(function () use ($operation, $outcome, $execute) {
            $record = $this->read($this->path($operation));
            if ($record['state'] !== 'pending') throw new RuntimeException('Only pending receipts can be reconciled.');
            $user = User::withTrashed()->whereKey($record['account_id'])->lockForUpdate()->first();
            $this->assertIdentity($record, $user);
            if (($outcome === 'completed') === ($user !== null)) throw new RuntimeException('Original database does not support this deletion outcome.');
            if ($execute) $this->finish($operation, $outcome);
        });
    }
}
