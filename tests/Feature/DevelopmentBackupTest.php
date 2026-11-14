<?php

use App\Services\BackupCipher;
use App\Services\BackupService;
use App\Services\BackupControl;
use App\Services\BackupEnvironment;
use App\Services\DatabaseBackupDump;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['backup.enabled' => true, 'app.maintenance.driver' => 'cache',
        'app.maintenance.store' => 'development_backup', 'deletion-ledger.enabled' => true,
        'deletion-ledger.activated_at' => now()->subDay()->toIso8601String()]);
    Storage::fake('profile_avatars');
    $this->mock(DatabaseBackupDump::class)->shouldReceive('export')->andReturnUsing(function ($writer, $work, $deadline, $check) {
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
    $result = app(BackupService::class)->create();
    expect($result['files'])->toBe(2)->and($result['verified'])->toBeTrue();
    $manifest = backupManifest($result['id']);
    expect(array_column($manifest['files'], 'source'))->toContain('private/report.bin', 'avatars/test.jpg');
    expect($manifest['restore_maintenance_required'])->toBeTrue();
    expect(app()->isDownForMaintenance())->toBeFalse();
    expect(app(BackupControl::class)->store()->has('lease'))->toBeFalse();
    expect(app(BackupService::class)->verify($result['id'])['verified'])->toBeTrue();
    $disk->assertExists('private/deletion-ledger/v1/keep.enc');
    $disk->assertExists('private/development-backups/v1/incomplete/keep.enc');
});

it('detects tampering, truncation, trailing bytes and wrong recovery keys', function () {
    $result = app(BackupService::class)->create();
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
    $old = app(BackupService::class)->create();
    $this->mock(DatabaseBackupDump::class)->shouldReceive('export')->andThrow(new RuntimeException('synthetic-export-failure'));
    expect(fn () => app(BackupService::class)->create())->toThrow(RuntimeException::class);
    expect(app()->isDownForMaintenance())->toBeFalse();
    expect(app(BackupService::class)->verify($old['id'])['verified'])->toBeTrue();
    expect(count(Storage::disk('profile_avatars')->allFiles('private/development-backups/v1')))->toBe(2);
});

it('refuses pre-existing operator maintenance and overlapping backup runs', function () {
    app()->maintenanceMode()->activate(['operator' => true]);
    expect(fn () => app(BackupService::class)->create())->toThrow(RuntimeException::class);
    expect(app()->isDownForMaintenance())->toBeTrue();
    app()->maintenanceMode()->deactivate();
    $control = app(BackupControl::class);
    $control->acquire('first');
    expect(fn () => $control->acquire('second'))->toThrow(RuntimeException::class);
    $control->release('second');
    expect(app()->isDownForMaintenance())->toBeTrue();
    $control->release('first');
});

it('preserves operator maintenance that replaces the backup-owned state during a failure', function () {
    $this->mock(DatabaseBackupDump::class)->shouldReceive('export')->andReturnUsing(function () {
        app()->maintenanceMode()->activate(['operator' => true]);
        throw new RuntimeException('synthetic interruption');
    });
    expect(fn () => app(BackupService::class)->create())->toThrow(RuntimeException::class);
    expect(app()->isDownForMaintenance())->toBeTrue()->and(app()->maintenanceMode()->data()['operator'])->toBeTrue();
    expect(app(BackupControl::class)->store()->has('lease'))->toBeFalse();
});

it('does not expire unconfirmed writer markers and aborts without disturbing them', function () {
    $control = app(BackupControl::class);
    $id = $control->beginActivity();
    config(['backup.drain_seconds' => 0]);
    expect(fn () => app(BackupService::class)->create())->toThrow(RuntimeException::class);
    expect($control->activeCount())->toBe(1)->and(app()->isDownForMaintenance())->toBeFalse();
    $control->endActivity($id);
});

it('enforces the cost sizing envelope and clears partial objects', function () {
    config(['backup.max_archive_bytes' => 10]);
    expect(fn () => app(BackupService::class)->create())->toThrow(RuntimeException::class);
    expect(Storage::disk('profile_avatars')->allFiles())->toBe([]);
    expect(app()->isDownForMaintenance())->toBeFalse();
});

it('refuses completion when a database-referenced private object is missing', function () {
    $user = \App\Models\User::factory()->create();
    \App\Models\AudioAttachment::create(['id' => (string) \Illuminate\Support\Str::uuid(), 'owner_type' => 'patient', 'owner_id' => $user->id,
        'client_id' => (string) \Illuminate\Support\Str::uuid(), 'purpose' => 'journal', 'disk' => 'local',
        'storage_key' => 'audio/missing.m4a', 'media_type' => 'audio/mp4', 'byte_size' => 1, 'duration' => 1]);
    expect(fn () => app(BackupService::class)->create())->toThrow(RuntimeException::class);
    expect(Storage::disk('profile_avatars')->allFiles())->toBe([])->and(app()->isDownForMaintenance())->toBeFalse();
    Storage::disk('profile_avatars')->put('private/audio/missing.m4a', 'synthetic');
    expect(app(BackupService::class)->create()['verified'])->toBeTrue();
});

it('retains complete copies for seven days and prunes only after a new verified copy', function () {
    $first = app(BackupService::class)->create();
    $this->travel(6)->days();
    app(BackupService::class)->create();
    Storage::disk('profile_avatars')->assertExists('private/development-backups/v1/'.$first['id'].'/manifest.enc');
    $this->travel(1)->days();
    $latest = app(BackupService::class)->create();
    expect($latest['pruned'])->toBe(1);
    Storage::disk('profile_avatars')->assertMissing('private/development-backups/v1/'.$first['id'].'/manifest.enc');
});

it('watchdog and the first request release expired backup maintenance but not restore maintenance', function () {
    $control = app(BackupControl::class);
    $control->acquire('expired');
    $lease = $control->store()->get('lease'); $lease['deadline'] = microtime(true) - 1;
    $control->store()->forever('lease', $lease);
    $this->getJson('/api/v1/config')->assertOk();
    expect(app()->isDownForMaintenance())->toBeFalse()->and($control->activeCount())->toBe(0);
    app()->maintenanceMode()->activate(['development_restore' => true]);
    expect($control->watchdog())->toBeFalse()->and(app()->isDownForMaintenance())->toBeTrue();
});

it('remains disabled by default and refuses production activation', function () {
    config(['backup.enabled' => false]);
    $this->artisan('development:backup')->assertFailed();
    config(['backup.enabled' => true]);
    $this->app->instance('env', 'production');
    expect(fn () => app(BackupControl::class)->assertReady())->toThrow(RuntimeException::class);
});

it('allows a dry run without maintenance or object writes and requires explicit execution', function () {
    $this->artisan('development:backup')->assertSuccessful();
    $this->artisan('backup:create')->assertSuccessful();
    expect(Storage::disk('profile_avatars')->allFiles())->toBe([])->and(app()->isDownForMaintenance())->toBeFalse();
});

it('accepts an explicitly approved production identity and rejects mismatches', function () {
    $this->app->instance('env', 'production');
    config([
        'app.url' => 'https://api.cozyhealth.org',
        'backup.environment' => 'production',
        'backup.project_id' => '33f9a938-49e3-42c5-83c7-633ee85760fa',
        'backup.environment_id' => '9ffd3fd1-ae52-4cc7-903e-35b529a151ae',
        'backup.maintenance_approved' => true,
    ]);
    app(BackupEnvironment::class)->assertApproved(maintenanceRequired: true);
    expect(app(BackupEnvironment::class)->name())->toBe('production');

    foreach ([
        'backup.maintenance_approved' => false,
        'backup.environment' => 'development',
        'backup.environment_id' => '56b85cc3-e80b-4cb9-a0d4-b47abe8586f6',
        'backup.project_id' => 'another-project',
        'app.url' => 'https://api.cozyhealth.org.attacker.example',
    ] as $key => $invalid) {
        $valid = config($key);
        config([$key => $invalid]);
        expect(fn () => app(BackupEnvironment::class)->assertApproved(maintenanceRequired: true))->toThrow(RuntimeException::class);
        config([$key => $valid]);
    }

    config(['backup.maintenance_approved' => false]);
    app(BackupEnvironment::class)->assertApproved();
});

it('keeps the shared production manifest isolated from development verification', function () {
    config(['backup.environment' => 'production', 'backup.prefix' => 'private/backups/v1']);
    $result = app(BackupService::class)->create();
    $path = 'private/backups/v1/'.$result['id'].'/manifest.enc';
    $manifest = json_decode(Crypt::decryptString(Storage::disk('profile_avatars')->get($path)), true, flags: JSON_THROW_ON_ERROR);
    expect($manifest['environment'])->toBe('production');
    expect(app(BackupService::class)->verify($result['id'])['verified'])->toBeTrue();
    config(['backup.environment' => 'development']);
    expect(fn () => app(BackupService::class)->verify($result['id']))->toThrow(RuntimeException::class);
});

it('preserves legacy development flags while requiring neutral production activation', function () {
    $names = ['APP_ENV', 'BACKUP_ENABLED', 'BACKUP_SCHEDULED', 'DEVELOPMENT_BACKUP_ENABLED', 'DEVELOPMENT_BACKUP_SCHEDULED'];
    $original = [];
    foreach ($names as $name) {
        $original[$name] = [getenv($name), $_ENV[$name] ?? null, $_SERVER[$name] ?? null];
        putenv($name);
        unset($_ENV[$name], $_SERVER[$name]);
    }
    $set = function (string $name, string $value): void {
        putenv($name.'='.$value);
        $_ENV[$name] = $_SERVER[$name] = $value;
    };
    try {
        $set('APP_ENV', 'development');
        $set('DEVELOPMENT_BACKUP_ENABLED', 'true');
        $set('DEVELOPMENT_BACKUP_SCHEDULED', 'true');
        $development = require base_path('config/backup.php');
        expect($development['enabled'])->toBeTrue()->and($development['scheduled'])->toBeTrue();
        expect($development['prefix'])->toBe('private/development-backups/v1');
        $set('APP_ENV', 'production');
        $production = require base_path('config/backup.php');
        expect($production['enabled'])->toBeFalse()->and($production['scheduled'])->toBeFalse();
        expect($production['prefix'])->toBe('private/backups/v1')->and($production['cache_store'])->toBe('backup_control');
        $set('BACKUP_ENABLED', 'true');
        $set('BACKUP_SCHEDULED', 'true');
        $activated = require base_path('config/backup.php');
        expect($activated['enabled'])->toBeTrue()->and($activated['scheduled'])->toBeTrue();
    } finally {
        foreach ($original as $name => [$value, $env, $server]) {
            $value === false ? putenv($name) : putenv($name.'='.$value);
            if ($env === null) { unset($_ENV[$name]); } else { $_ENV[$name] = $env; }
            if ($server === null) { unset($_SERVER[$name]); } else { $_SERVER[$name] = $server; }
        }
    }
});

it('permits operator recovery commands in manual maintenance but blocks them during a live backup', function () {
    $control = app(BackupControl::class);
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
