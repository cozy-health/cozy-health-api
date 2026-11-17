<?php

use App\Models\AudioAttachment;
use App\Models\User;
use App\Services\AccountDeletion;
use App\Services\Apple\AppleTokenService;
use App\Services\CommunityPrivacy;
use App\Services\DeletionLedger;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['deletion-ledger.enabled' => true, 'deletion-ledger.activated_at' => now()->subDays(7)->toIso8601String()]);
    Storage::fake('local');
    Mail::fake();
    Http::preventStrayRequests();
});

function deletionRecords(): array
{
    return array_map(fn ($path) => json_decode(Crypt::decryptString(Storage::disk('local')->get($path)), true, flags: JSON_THROW_ON_ERROR), Storage::disk('local')->files('deletion-ledger/v1'));
}

it('stores an encrypted minimal receipt and preserves unrelated accounts', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $this->withToken($user->createToken('delete')->plainTextToken)->deleteJson('/api/v1/account')->assertOk();
    $receipt = deletionRecords()[0];
    expect($receipt['state'])->toBe('completed')
        ->and($receipt['account_id'])->toBe($user->id)
        ->and(array_keys($receipt))->not->toContain('email', 'password', 'name');
    expect(Storage::disk('local')->get(Storage::disk('local')->files('deletion-ledger/v1')[0]))->not->toContain($user->email);
    $this->assertDatabaseMissing('users', ['id' => $user->id]);
    $this->assertDatabaseHas('users', ['id' => $other->id]);
    Http::assertNothingSent();
});

it('does not create receipts for unauthenticated or demo deletion', function () {
    $this->deleteJson('/api/v1/account')->assertUnauthorized();
    $demo = User::factory()->create(['is_demo' => true]);
    $this->withToken($demo->createToken('demo')->plainTextToken)->deleteJson('/api/v1/account')->assertForbidden();
    expect(deletionRecords())->toBe([]);
});

it('refuses deletion when private receipt storage fails', function () {
    $user = User::factory()->create();
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('put')->once()->andReturnFalse();
    Storage::shouldReceive('disk')->with('local')->andReturn($disk);
    expect(fn () => app(AccountDeletion::class)->delete($user, app(AppleTokenService::class)))->toThrow(RuntimeException::class);
    $this->assertDatabaseHas('users', ['id' => $user->id]);
    Mail::assertNothingSent();
});

it('records an aborted operation when database cleanup rolls back', function () {
    $user = User::factory()->create();
    $token = $user->createToken('retained');
    $this->mock(CommunityPrivacy::class)->shouldReceive('removeAccount')->once()->andThrow(new RuntimeException('synthetic database failure'));
    expect(fn () => app(AccountDeletion::class)->delete($user, app(AppleTokenService::class)))->toThrow(RuntimeException::class);
    expect(deletionRecords()[0]['state'])->toBe('aborted');
    $this->assertDatabaseHas('users', ['id' => $user->id]);
    $this->assertDatabaseHas('personal_access_tokens', ['id' => $token->accessToken->id]);
    Mail::assertNothingSent();
});

it('replays a synthetic restored account and private files without mail or provider calls', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $audio = AudioAttachment::create([
        'id' => (string) Str::uuid(), 'owner_type' => 'patient', 'owner_id' => $user->id,
        'client_id' => (string) Str::uuid(), 'purpose' => 'journal', 'disk' => 'local',
        'storage_key' => 'audio/synthetic.m4a', 'media_type' => 'audio/mp4', 'byte_size' => 17, 'duration' => 1,
    ]);
    Storage::disk('local')->put($audio->storage_key, 'synthetic audio');
    $user->createToken('restored-device');
    $ledger = app(DeletionLedger::class);
    $operation = (string) Str::uuid();
    $ledger->begin($user, $operation);
    $ledger->finish($operation, 'completed');
    expect($ledger->replay(now()->subHour()))->toBe(1);
    $this->assertDatabaseHas('users', ['id' => $user->id]);
    expect($ledger->replay(now()->subHour(), true))->toBe(1);
    $this->assertDatabaseMissing('users', ['id' => $user->id]);
    $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $user->id]);
    $this->assertDatabaseHas('users', ['id' => $other->id]);
    $this->artisan('avatars:cleanup')->assertSuccessful();
    Storage::disk('local')->assertMissing($audio->storage_key);
    expect($ledger->replay(now()->subHour(), true))->toBe(1);
    Mail::assertNothingSent();
    Http::assertNothingSent();
});

it('blocks the whole replay before writes if any operation is pending', function () {
    $ledger = app(DeletionLedger::class);
    $user = User::factory()->create();
    $op = (string) Str::uuid();
    $ledger->begin($user, $op);
    $ledger->finish($op, 'completed');
    $ledger->begin(User::factory()->create(), (string) Str::uuid());
    expect(fn () => $ledger->replay(now()->subHour(), true))->toThrow(RuntimeException::class);
    $this->assertDatabaseHas('users', ['id' => $user->id]);
});

it('rejects a reused numeric ID with a different original creation timestamp', function () {
    $user = User::factory()->create();
    $ledger = app(DeletionLedger::class);
    $op = (string) Str::uuid();
    $ledger->begin($user, $op);
    $ledger->finish($op, 'completed');
    DB::table('users')->where('id', $user->id)->update(['created_at' => now()->subDay()]);
    expect(fn () => $ledger->replay(now()->subHour(), true))->toThrow(RuntimeException::class);
    $this->assertDatabaseHas('users', ['id' => $user->id]);
});

it('retains pending records but prunes resolved records at fourteen days', function () {
    $ledger = app(DeletionLedger::class);
    $user = User::factory()->create();
    foreach (['completed', 'aborted', 'pending'] as $state) {
        $op = (string) Str::uuid();
        $ledger->begin($user, $op);
        if ($state !== 'pending') $ledger->finish($op, $state);
    }
    $this->travel(13)->days();
    expect($ledger->prune(true))->toBe(0);
    $this->travel(1)->days();
    expect($ledger->prune())->toBe(2)->and(count(deletionRecords()))->toBe(3);
    expect($ledger->prune(true))->toBe(2)->and(count(deletionRecords()))->toBe(1);
    expect(deletionRecords()[0]['state'])->toBe('pending');
});

it('rejects damaged records, old snapshots and execution outside maintenance', function () {
    $ledger = app(DeletionLedger::class);
    expect(fn () => $ledger->replay(now()->subDays(8), true))->toThrow(RuntimeException::class);
    Storage::disk('local')->put('deletion-ledger/v1/damaged.json.enc', 'corrupt');
    expect(fn () => $ledger->replay(now()->subHour(), true))->toThrow(\Illuminate\Contracts\Encryption\DecryptException::class);
    $this->artisan('privacy:deletions-replay', ['--snapshot' => now()->subHour()->format('Y-m-d\TH:i:sP'), '--execute' => true])->assertFailed();
});

it('refuses a restore that predates ledger activation even when no receipts exist', function () {
    config(['deletion-ledger.activated_at' => now()->subMinutes(30)->toIso8601String()]);
    expect(fn () => app(DeletionLedger::class)->replay(now()->subHour(), true))->toThrow(RuntimeException::class);
    config(['deletion-ledger.activated_at' => null]);
    expect(fn () => app(DeletionLedger::class)->assertAvailable())->toThrow(RuntimeException::class);
});

it('leaves a pending receipt and reports no success if the final acknowledgement fails', function () {
    $user = User::factory()->create();
    $real = Storage::disk('local');
    $disk = Mockery::mock(Filesystem::class);
    $writes = 0;
    $disk->shouldReceive('put')->twice()->andReturnUsing(function (...$args) use ($real, &$writes) {
        if (++$writes > 1) throw new RuntimeException('synthetic completion failure');
        return $real->put(...$args);
    });
    $disk->shouldReceive('get')->andReturnUsing(fn ($path) => $real->get($path));
    $disk->shouldReceive('files')->andReturnUsing(fn ($path) => $real->files($path));
    Storage::shouldReceive('disk')->with('local')->andReturn($disk);
    expect(fn () => app(AccountDeletion::class)->delete($user, app(AppleTokenService::class)))->toThrow(RuntimeException::class);
    $this->assertDatabaseMissing('users', ['id' => $user->id]);
    expect(deletionRecords()[0]['state'])->toBe('pending');
    Mail::assertNothingSent();
});

it('creates no receipt when a verified privacy request identity has changed', function () {
    $user = User::factory()->create();
    expect(fn () => app(AccountDeletion::class)->delete($user, app(AppleTokenService::class), 'wrong-identity'))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect(deletionRecords())->toBe([]);
    $this->assertDatabaseHas('users', ['id' => $user->id]);
});

it('rejects production activation and unsafe encrypted file references', function () {
    $ledger = app(DeletionLedger::class);
    $op = (string) Str::uuid();
    $ledger->begin(User::factory()->create(), $op);
    $ledger->finish($op, 'completed');
    $record = deletionRecords()[0];
    $record['files'] = [['disk' => 'local', 'key' => '../unrelated']];
    Storage::disk('local')->put('deletion-ledger/v1/'.$op.'.json.enc', Crypt::encryptString(json_encode($record, JSON_THROW_ON_ERROR)));
    expect(fn () => $ledger->replay(now()->subHour(), true))->toThrow(RuntimeException::class);
    $this->app->instance('env', 'production');
    expect(fn () => $ledger->assertAvailable())->toThrow(RuntimeException::class);
});

it('is disabled unless explicitly enabled and does not write when disabled', function () {
    config(['deletion-ledger.enabled' => false]);
    $user = User::factory()->create();
    app(AccountDeletion::class)->delete($user, app(AppleTokenService::class));
    expect(deletionRecords())->toBe([]);
    $this->artisan('privacy:deletions-prune', ['--execute' => true])->assertFailed();
});

it('reconciles pending outcomes only against matching original database evidence', function () {
    $user = User::factory()->create();
    $op = (string) Str::uuid();
    $ledger = app(DeletionLedger::class);
    $ledger->begin($user, $op);
    expect(fn () => $ledger->reconcile($op, 'completed', true))->toThrow(RuntimeException::class);
    $this->artisan('privacy:deletions-reconcile', ['operation' => $op, 'outcome' => 'aborted', '--execute' => true])->assertFailed();
    $ledger->reconcile($op, 'aborted');
    expect(deletionRecords()[0]['state'])->toBe('pending');
    $ledger->reconcile($op, 'aborted', true);
    expect(deletionRecords()[0]['state'])->toBe('aborted');

    $next = (string) Str::uuid();
    $ledger->begin($user, $next);
    app(AccountDeletion::class)->replay($user);
    expect(fn () => $ledger->reconcile($next, 'aborted', true))->toThrow(RuntimeException::class);
    $ledger->reconcile($next, 'completed', true);
    expect(app(DeletionLedger::class)->replay(now()->subHour()))->toBe(1);
});
