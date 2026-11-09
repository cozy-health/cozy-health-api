<?php

use App\Services\BackupCipher;
use App\Services\DevelopmentBackup;
use App\Services\DevelopmentBackupControl;
use App\Services\DevelopmentDatabaseDump;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['development-backup.enabled' => true, 'app.maintenance.driver' => 'cache',
        'app.maintenance.store' => 'development_backup', 'deletion-ledger.enabled' => true,
        'deletion-ledger.activated_at' => now()->subDay()->toIso8601String()]);
    Storage::fake('profile_avatars');
    $this->mock(DevelopmentDatabaseDump::class)->shouldReceive('export')->andReturnUsing(function ($writer, $work, $deadline, $check) {
        $check(); $writer->append("CREATE TABLE synthetic (id INT);\nINSERT INTO synthetic VALUES(1);\n");
    });
});

function backupManifest(string $id): array
{
    return json_decode(Crypt::decryptString(Storage::disk('profile_avatars')->get('private/development-backups/v1/'.$id.'/manifest.enc')), true, flags: JSON_THROW_ON_ERROR);
}

it('creates an authenticated recovery copy without copying ledgers or older backup contents', function () {
    $disk = Storage::disk('profile_avatars');
    $disk->put('private/report.bin', random_bytes(100000));
    $disk->put('avatars/test.jpg', 'synthetic-avatar');
    $disk->put('private/deletion-ledger/v1/keep.enc', 'independent-receipt');
    $disk->put('private/development-backups/v1/incomplete/keep.enc', 'older-copy');
    $result = app(DevelopmentBackup::class)->create();
    expect($result['files'])->toBe(2)->and($result['verified'])->toBeTrue();
    $manifest = backupManifest($result['id']);
    expect(array_column($manifest['files'], 'source'))->toContain('private/report.bin', 'avatars/test.jpg');
    expect($manifest['restore_maintenance_required'])->toBeTrue();
    expect(app()->isDownForMaintenance())->toBeFalse();
    expect(app(DevelopmentBackupControl::class)->store()->has('lease'))->toBeFalse();
    expect(app(DevelopmentBackup::class)->verify($result['id'])['verified'])->toBeTrue();
    $disk->assertExists('private/deletion-ledger/v1/keep.enc');
    $disk->assertExists('private/development-backups/v1/incomplete/keep.enc');
});

it('detects tampering, truncation, trailing bytes and wrong recovery keys', function () {
    $result = app(DevelopmentBackup::class)->create();
    $m = backupManifest($result['id']);
    $key = base64_decode($m['key']);
    $cipher = Storage::disk('profile_avatars')->get($m['database']['object']);
    foreach ([substr($cipher, 0, -1), $cipher.'extra', substr_replace($cipher, chr(ord($cipher[40]) ^ 1), 40, 1)] as $bad) {
        $stream = fopen('php://temp', 'w+b'); fwrite($stream, $bad); rewind($stream);
        try { expect(fn () => BackupCipher::verify($stream, $key, $m['database']))->toThrow(RuntimeException::class); }
        finally { fclose($stream); }
    }
    $stream = fopen('php://temp', 'w+b'); fwrite($stream, $cipher); rewind($stream);
    try { expect(fn () => BackupCipher::verify($stream, random_bytes(32), $m['database']))->toThrow(RuntimeException::class); }
    finally { fclose($stream); }
});

it('releases only its own maintenance when export fails and preserves old backups', function () {
    $old = app(DevelopmentBackup::class)->create();
    $this->mock(DevelopmentDatabaseDump::class)->shouldReceive('export')->andThrow(new RuntimeException('synthetic-export-failure'));
    expect(fn () => app(DevelopmentBackup::class)->create())->toThrow(RuntimeException::class);
    expect(app()->isDownForMaintenance())->toBeFalse();
    expect(app(DevelopmentBackup::class)->verify($old['id'])['verified'])->toBeTrue();
    expect(count(Storage::disk('profile_avatars')->allFiles('private/development-backups/v1')))->toBe(2);
});

it('refuses pre-existing operator maintenance and overlapping backup runs', function () {
    app()->maintenanceMode()->activate(['operator' => true]);
    expect(fn () => app(DevelopmentBackup::class)->create())->toThrow(RuntimeException::class);
    expect(app()->isDownForMaintenance())->toBeTrue();
    app()->maintenanceMode()->deactivate();
    $control = app(DevelopmentBackupControl::class);
    $control->acquire('first');
    expect(fn () => $control->acquire('second'))->toThrow(RuntimeException::class);
    $control->release('second');
    expect(app()->isDownForMaintenance())->toBeTrue();
    $control->release('first');
});

it('preserves operator maintenance that replaces the backup-owned state during a failure', function () {
    $this->mock(DevelopmentDatabaseDump::class)->shouldReceive('export')->andReturnUsing(function () {
        app()->maintenanceMode()->activate(['operator' => true]);
        throw new RuntimeException('synthetic interruption');
    });
    expect(fn () => app(DevelopmentBackup::class)->create())->toThrow(RuntimeException::class);
    expect(app()->isDownForMaintenance())->toBeTrue()->and(app()->maintenanceMode()->data()['operator'])->toBeTrue();
    expect(app(DevelopmentBackupControl::class)->store()->has('lease'))->toBeFalse();
});

it('does not expire unconfirmed writer markers and aborts without disturbing them', function () {
    $control = app(DevelopmentBackupControl::class);
    $id = $control->beginActivity();
    config(['development-backup.drain_seconds' => 0]);
    expect(fn () => app(DevelopmentBackup::class)->create())->toThrow(RuntimeException::class);
    expect($control->activeCount())->toBe(1)->and(app()->isDownForMaintenance())->toBeFalse();
    $control->endActivity($id);
});

it('enforces the cost sizing envelope and clears partial objects', function () {
    config(['development-backup.max_archive_bytes' => 10]);
    expect(fn () => app(DevelopmentBackup::class)->create())->toThrow(RuntimeException::class);
    expect(Storage::disk('profile_avatars')->allFiles())->toBe([]);
    expect(app()->isDownForMaintenance())->toBeFalse();
});

it('refuses completion when a database-referenced private object is missing', function () {
    $user = \App\Models\User::factory()->create();
    \App\Models\AudioAttachment::create(['id' => (string) \Illuminate\Support\Str::uuid(), 'owner_type' => 'patient', 'owner_id' => $user->id,
        'client_id' => (string) \Illuminate\Support\Str::uuid(), 'purpose' => 'journal', 'disk' => 'local',
        'storage_key' => 'audio/missing.m4a', 'media_type' => 'audio/mp4', 'byte_size' => 1, 'duration' => 1]);
    expect(fn () => app(DevelopmentBackup::class)->create())->toThrow(RuntimeException::class);
    expect(Storage::disk('profile_avatars')->allFiles())->toBe([])->and(app()->isDownForMaintenance())->toBeFalse();
    Storage::disk('profile_avatars')->put('private/audio/missing.m4a', 'synthetic');
    expect(app(DevelopmentBackup::class)->create()['verified'])->toBeTrue();
});

it('retains complete copies for seven days and prunes only after a new verified copy', function () {
    $first = app(DevelopmentBackup::class)->create();
    $this->travel(6)->days();
    app(DevelopmentBackup::class)->create();
    Storage::disk('profile_avatars')->assertExists('private/development-backups/v1/'.$first['id'].'/manifest.enc');
    $this->travel(1)->days();
    $latest = app(DevelopmentBackup::class)->create();
    expect($latest['pruned'])->toBe(1);
    Storage::disk('profile_avatars')->assertMissing('private/development-backups/v1/'.$first['id'].'/manifest.enc');
});

it('watchdog and the first request release expired backup maintenance but not restore maintenance', function () {
    $control = app(DevelopmentBackupControl::class);
    $control->acquire('expired');
    $lease = $control->store()->get('lease'); $lease['deadline'] = microtime(true) - 1;
    $control->store()->forever('lease', $lease);
    $this->getJson('/api/v1/config')->assertOk();
    expect(app()->isDownForMaintenance())->toBeFalse()->and($control->activeCount())->toBe(0);
    app()->maintenanceMode()->activate(['development_restore' => true]);
    expect($control->watchdog())->toBeFalse()->and(app()->isDownForMaintenance())->toBeTrue();
});

it('remains disabled by default and refuses production activation', function () {
    config(['development-backup.enabled' => false]);
    $this->artisan('development:backup')->assertFailed();
    config(['development-backup.enabled' => true]);
    $this->app->instance('env', 'production');
    expect(fn () => app(DevelopmentBackupControl::class)->assertReady())->toThrow(RuntimeException::class);
});

it('allows a dry run without maintenance or object writes and requires explicit execution', function () {
    $this->artisan('development:backup')->assertSuccessful();
    expect(Storage::disk('profile_avatars')->allFiles())->toBe([])->and(app()->isDownForMaintenance())->toBeFalse();
});

it('permits operator recovery commands in manual maintenance but blocks them during a live backup', function () {
    $control = app(DevelopmentBackupControl::class);
    app()->maintenanceMode()->activate(['development_restore' => true]);
    $control->commandStarting('privacy:deletions-replay');
    expect($control->activeCount())->toBe(1);
    $control->commandFinished('privacy:deletions-replay');
    app()->maintenanceMode()->deactivate();
    $control->acquire('backup');
    expect(fn () => $control->commandStarting('privacy:deletions-replay'))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect($control->activeCount())->toBe(0);
    $control->release('backup');
});
