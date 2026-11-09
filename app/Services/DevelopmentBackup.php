<?php
namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class DevelopmentBackup
{
    private string $id;
    private float $deadline;
    private int $bytes = 0;
    private int $sequence = 0;

    private function disk()
    {
        if (app()->environment('testing')) return Storage::disk(config('development-backup.disk'));
        $cfg = config('filesystems.disks.'.config('development-backup.disk'));
        if (($cfg['driver'] ?? '') !== 's3' || filled($cfg['root'] ?? null)) throw new RuntimeException('Expected the existing private bucket root.');
        $private = config('filesystems.disks.local');
        if (($private['driver'] ?? '') !== 's3' || ($private['root'] ?? '') !== 'private' || ($private['bucket'] ?? null) !== ($cfg['bucket'] ?? null) || ($private['endpoint'] ?? null) !== ($cfg['endpoint'] ?? null)) throw new RuntimeException('Private storage must use the verified development bucket layout.');
        $cfg['http'] = ['connect_timeout' => 2, 'timeout' => 5, 'verify' => true];
        $cfg['retries'] = 0; $cfg['throw'] = true;
        return Storage::build($cfg);
    }
    private function root(string $id): string
    {
        if (! Str::isUuid($id)) throw new RuntimeException('Invalid backup ID.');
        return config('development-backup.prefix').'/'.$id;
    }
    private function checkpoint(): void
    {
        if (microtime(true) > $this->deadline - 10 || ! app(DevelopmentBackupControl::class)->owns($this->id)) throw new RuntimeException('Backup lease/window lost.');
    }
    public function create(): array
    {
        $control = app(DevelopmentBackupControl::class);
        $control->assertReady();
        app(DeletionLedger::class)->assertAvailable();
        if (! is_executable(config('development-backup.dump_binary')) && ! app()->environment('testing')) throw new RuntimeException('Native export client unavailable.');
        $this->id = (string) Str::uuid(); $this->bytes = 0; $this->sequence = 0;
        $started = microtime(true); $acquired = false; $committed = false; $work = null;
        $disk = $this->disk(); $root = $this->root($this->id);
        try {
            $this->deadline = $control->acquire($this->id); $acquired = true;
            $control->waitForDrain($this->deadline);
            $snapshot = now()->toIso8601String();
            $work = storage_path('app/backup-work/'.$this->id);
            if (! mkdir($work, 0700, true)) throw new RuntimeException('Private backup work directory failed.');
            chmod($work, 0700);
            $key = random_bytes(SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES);
            $writer = new BackupCipher($work.'/database.enc', $key, true);
            app(DevelopmentDatabaseDump::class)->export($writer, $work, $this->deadline, fn () => $this->checkpoint());
            $database = $this->upload($disk, $work.'/database.enc', $root.'/database.enc', $writer->finish(), $key);
            $files = [];
            // Existing development bucket contains private files and avatars. Ledger
            // receipts remain independent and are never copied backwards on restore.
            foreach ($disk->getAdapter()->listContents('', true) as $entry) {
                if (! $entry->isFile()) continue;
                $source = $entry->path();
                if (str_starts_with($source, 'private/deletion-ledger/') || str_starts_with($source, config('development-backup.prefix').'/')) continue;
                $this->safeSource($source);
                if (count($files) >= config('development-backup.max_files')) throw new RuntimeException('Backup file inventory limit exceeded.');
                $this->checkpoint();
                $input = $disk->readStream($source);
                if (! is_resource($input)) throw new RuntimeException('Private source file unavailable.');
                $local = $work.'/object.enc';
                $writer = new BackupCipher($local, $key, false);
                try {
                    while (! feof($input)) { $this->checkpoint(); $part = fread($input, 65536); if ($part === false) throw new RuntimeException('Private source read failed.'); if ($part !== '') $writer->append($part); }
                } finally { fclose($input); }
                $files[] = ['source' => $source] + $this->upload($disk, $local, $root.'/files/'.(++$this->sequence).'.enc', $writer->finish(), $key);
            }
            $this->assertRequiredFiles(array_column($files, 'source'));
            $this->checkpoint();
            $manifest = ['schema' => 1, 'id' => $this->id, 'environment' => 'development', 'snapshot_at' => $snapshot,
                'completed_at' => now()->toIso8601String(), 'expires_at' => Carbon::parse($snapshot)->addDays(7)->toIso8601String(),
                'key' => base64_encode($key), 'database' => $database, 'files' => $files,
                'restore_maintenance_required' => true, 'cipher_bytes' => $this->bytes];
            $encrypted = Crypt::encryptString(json_encode($manifest, JSON_THROW_ON_ERROR));
            $this->checkSize(strlen($encrypted));
            if (! $disk->put($root.'/manifest.enc', $encrypted, ['visibility' => 'private'])) throw new RuntimeException('Backup manifest upload failed.');
            if (! hash_equals(hash('sha256', $encrypted), hash('sha256', $disk->get($root.'/manifest.enc')))) throw new RuntimeException('Backup manifest readback failed.');
            $this->checkpoint(); $committed = true;
            $result = ['id' => $this->id, 'snapshot_at' => $snapshot, 'files' => count($files), 'cipher_bytes' => $this->bytes, 'seconds' => round(microtime(true) - $started, 3), 'verified' => true];
            $control->store()->forever('last-success', $result);
        } finally {
            if (isset($writer)) $writer->close();
            // Availability restoration precedes any remote partial-object cleanup.
            if ($acquired) $control->release($this->id);
            if (! $committed) {
                try { $disk->deleteDirectory($root); } catch (\Throwable) { Log::warning('Incomplete development backup needs scoped cleanup.'); }
            }
            if ($work && is_dir($work)) { foreach (['database.enc', 'object.enc', 'client.cnf'] as $file) if (is_file($work.'/'.$file)) unlink($work.'/'.$file); rmdir($work); }
        }
        // Retention is permitted only after a newly verified backup succeeded.
        $result['pruned'] = $this->prune($this->id);
        return $result;
    }
    private function upload($disk, string $local, string $target, array $meta, string $key): array
    {
        $this->checkpoint(); $this->checkSize($meta['cipher_bytes']);
        $stream = fopen($local, 'rb');
        try { if (! $disk->put($target, $stream, ['visibility' => 'private'])) throw new RuntimeException('Encrypted upload failed.'); }
        finally { fclose($stream); }
        $this->checkpoint();
        $readback = $disk->readStream($target);
        if (! is_resource($readback)) throw new RuntimeException('Encrypted readback unavailable.');
        try { BackupCipher::verify($readback, $key, $meta); } finally { fclose($readback); }
        unlink($local);
        return ['object' => $target] + $meta;
    }
    private function checkSize(int $bytes): void
    {
        $this->bytes += $bytes;
        if ($this->bytes > config('development-backup.max_archive_bytes')) throw new RuntimeException('Archive exceeds the approved estimate envelope; review usage before scheduling.');
    }
    private function safeSource(string $key): void
    {
        if ($key === '' || str_starts_with($key, '/') || str_contains($key, '\\') || in_array('..', explode('/', $key), true)) throw new RuntimeException('Invalid source object key.');
    }
    private function assertRequiredFiles(array $sources): void
    {
        $present = array_fill_keys($sources, true);
        foreach (DB::table('audio_attachments')->select('disk', 'storage_key')->cursor() as $row) {
            $key = match ($row->disk) { 'local' => 'private/'.$row->storage_key, config('avatars.disk') => $row->storage_key, default => throw new RuntimeException('Unsupported audio storage disk.') };
            if (! isset($present[$key])) throw new RuntimeException('A referenced private audio object is absent from the backup.');
        }
        foreach (['provider_reports' => 'file_path', 'student_applications' => 'evidence_path', 'user_profiles' => 'avatar_object_key', 'community_posts' => 'image_object_key'] as $table => $column) {
            foreach (DB::table($table)->whereNotNull($column)->where($column, '<>', '')->pluck($column) as $path) {
                $key = in_array($table, ['provider_reports', 'student_applications'], true) ? 'private/'.$path : $path;
                if (! isset($present[$key])) throw new RuntimeException('A referenced private object is absent from the backup.');
            }
        }
    }
    public function prune(string $freshId): int
    {
        app(DevelopmentBackupControl::class)->assertReady();
        $disk = $this->disk();
        $fresh = $this->manifest($disk, $this->root($freshId).'/manifest.enc');
        if (Carbon::parse($fresh['completed_at'])->lessThan(now()->subMinutes(10))) throw new RuntimeException('A fresh verified backup is required before retention cleanup.');
        $expired = [];
        foreach ($disk->getAdapter()->listContents(config('development-backup.prefix'), true) as $entry) {
            if (! $entry->isFile() || ! str_ends_with($entry->path(), '/manifest.enc')) continue;
            $manifest = $this->manifest($disk, $entry->path());
            if ($manifest['id'] !== $freshId && Carbon::parse($manifest['expires_at'])->lessThanOrEqualTo(now())) $expired[] = $this->root($manifest['id']);
        }
        foreach ($expired as $path) if (! $disk->deleteDirectory($path)) throw new RuntimeException('Expired backup cleanup failed.');
        return count($expired);
    }
    private function manifest($disk, string $path): array
    {
        $m = json_decode(Crypt::decryptString($disk->get($path)), true, 64, JSON_THROW_ON_ERROR);
        if (! is_array($m) || ($m['schema'] ?? null) !== 1 || ($m['environment'] ?? null) !== 'development' || ! is_string($m['id'] ?? null) || $this->root($m['id']).'/manifest.enc' !== $path
            || ! is_string($m['snapshot_at'] ?? null) || ! is_string($m['completed_at'] ?? null) || ! is_string($m['expires_at'] ?? null)
            || Carbon::parse($m['expires_at'])->notEqualTo(Carbon::parse($m['snapshot_at'])->addDays(7))) throw new RuntimeException('Invalid backup manifest.');
        return $m;
    }

    public function verify(string $id): array
    {
        app(DevelopmentBackupControl::class)->assertReady();
        $disk = $this->disk();
        $manifest = $this->manifest($disk, $this->root($id).'/manifest.enc');
        $key = base64_decode($manifest['key'] ?? '', true);
        if (! is_string($key) || strlen($key) !== 32) throw new RuntimeException('Backup recovery key is invalid.');
        foreach ([$manifest['database'], ...$manifest['files']] as $object) {
            if (! is_array($object) || ! str_starts_with($object['object'] ?? '', $this->root($id).'/')) throw new RuntimeException('Invalid backup object path.');
            $input = $disk->readStream($object['object']);
            if (! is_resource($input)) throw new RuntimeException('Backup object unavailable.');
            try { BackupCipher::verify($input, $key, $object); } finally { fclose($input); }
        }
        return ['id' => $id, 'snapshot_at' => $manifest['snapshot_at'], 'files' => count($manifest['files']), 'verified' => true];
    }
}
