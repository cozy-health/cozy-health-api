<?php

use App\Models\User;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['avatars.disk' => 'avatar_test']);
    Storage::fake('avatar_test')->buildTemporaryUrlsUsing(fn ($path, $expiration) => 'https://storage.example.test/'.$path.'?expires='.$expiration->timestamp);
});

it('requires authentication for avatar operations', function () {
    $this->getJson('/api/v1/user/profile/avatar')->assertUnauthorized();
    $this->putJson('/api/v1/user/profile/avatar', ['preset_id' => 'open-peeps-01'])->assertUnauthorized();
    $this->postJson('/api/v1/user/profile/avatar')->assertUnauthorized();
    $this->deleteJson('/api/v1/user/profile/avatar')->assertUnauthorized();
});

it('selects all 24 bundled avatars and can explicitly remove a picture', function () {
    Sanctum::actingAs(User::factory()->create());
    for ($i = 1; $i <= 24; $i++) {
        $id = sprintf('open-peeps-%02d', $i);
        $this->putJson('/api/v1/user/profile/avatar', ['preset_id' => $id])->assertOk()->assertJsonPath('data.avatar_url', 'asset:'.$id)->assertJsonPath('data.avatar_revision', $i);
    }
    $this->deleteJson('/api/v1/user/profile/avatar')->assertOk()->assertJsonPath('data.avatar_url', null)->assertJsonPath('data.avatar_revision', 25);
    $this->getJson('/api/v1/user/profile')->assertOk()->assertJsonPath('data.avatar_url', null);
});

it('rejects unknown presets and cannot modify another users avatar', function () {
    $one = User::factory()->create();
    $two = User::factory()->create();
    Sanctum::actingAs($one);
    $this->putJson('/api/v1/user/profile/avatar', ['preset_id' => 'open-peeps-01', 'user_id' => $two->id])->assertOk();
    foreach (['open-peeps-00', 'open-peeps-25', '../photo', 'https://external.test/image.png'] as $id) {
        $this->putJson('/api/v1/user/profile/avatar', ['preset_id' => $id])->assertUnprocessable();
    }
    Sanctum::actingAs($two);
    $this->getJson('/api/v1/user/profile/avatar')->assertJsonPath('data.avatar_url', null);
    $this->deleteJson('/api/v1/user/profile/avatar')->assertOk();
    Sanctum::actingAs($one);
    expect($one->profile()->first()->avatar_url)->toBe('asset:open-peeps-01');
});

it('ordinary updates and offline sync cannot overwrite a managed avatar', function () {
    Sanctum::actingAs($user = User::factory()->create());
    $this->putJson('/api/v1/user/profile/avatar', ['preset_id' => 'open-peeps-05'])->assertOk();
    $this->patchJson('/api/v1/user/profile', ['name' => 'Edited', 'avatar_url' => null, 'avatar_preset_id' => null])->assertOk();
    $id = $user->profile()->first()->id;
    $this->postJson('/api/v1/sync/batch', ['operations' => [['type' => 'user_profile', 'action' => 'upsert', 'data' => ['id' => $id, 'name' => 'Offline edit', 'avatar_url' => 'https://old.test/photo', 'avatar_revision' => 999, 'avatar_preset_id' => 'open-peeps-24']]]])->assertOk()->assertJsonPath('data.results.0.success', true);
    $this->getJson('/api/v1/user/profile')->assertOk()->assertJsonPath('data.avatar_url', 'asset:open-peeps-05')->assertJsonPath('data.avatar_revision', 1);
});

it('normalizes photo uploads and durably cleans replaced files', function () {
    Sanctum::actingAs($user = User::factory()->create());
    $this->postJson('/api/v1/user/profile/avatar', ['photo' => UploadedFile::fake()->image('portrait.png', 800, 600)])->assertOk();
    $profile = $user->profile()->first();
    $key = $profile->avatar_object_key;
    $this->getJson('/api/v1/user/profile')->assertOk()->assertJsonMissingPath('data.avatar_object_key');
    expect($profile->avatar_url)->toStartWith('https://storage.example.test/avatars/');
    Storage::disk('avatar_test')->assertExists($key);
    $bytes = Storage::disk('avatar_test')->get($key);
    $dimensions = getimagesizefromstring($bytes);
    expect([$dimensions[0], $dimensions[1], $dimensions[2]])->toBe([512, 512, IMAGETYPE_JPEG]);
    $this->putJson('/api/v1/user/profile/avatar', ['preset_id' => 'open-peeps-03'])->assertOk();
    expect(DB::table('avatar_photo_deletions')->count())->toBe(1);
    $this->artisan('avatars:cleanup')->assertSuccessful();
    Storage::disk('avatar_test')->assertMissing($key);
    expect(DB::table('avatar_photo_deletions')->count())->toBe(0);
});

it('invalid uploads leave the previous avatar unchanged', function () {
    Sanctum::actingAs(User::factory()->create());
    $this->putJson('/api/v1/user/profile/avatar', ['preset_id' => 'open-peeps-02'])->assertOk();
    foreach ([UploadedFile::fake()->create('not-an-image.jpg', 1, 'text/plain'), UploadedFile::fake()->image('huge.jpg')->size(5121), UploadedFile::fake()->image('wide.png', 4100, 10)] as $file) {
        $this->postJson('/api/v1/user/profile/avatar', ['photo' => $file])->assertUnprocessable();
    }
    $this->getJson('/api/v1/user/profile/avatar')->assertJsonPath('data.avatar_url', 'asset:open-peeps-02');
});

it('preserves a legacy URL until the user chooses a managed avatar', function () {
    Sanctum::actingAs($user = User::factory()->create());
    $profile = $user->profile()->create(['id' => (string) Str::uuid(), 'name' => $user->name, 'email' => $user->email]);
    $profile->forceFill(['avatar_url' => 'https://legacy.test/picture.jpg'])->save();
    $this->getJson('/api/v1/user/profile')->assertJsonPath('data.avatar_url', 'https://legacy.test/picture.jpg');
    $this->deleteJson('/api/v1/user/profile/avatar')->assertOk();
    $this->getJson('/api/v1/user/profile')->assertJsonPath('data.avatar_url', null);
});

it('account deletion schedules photo cleanup without retaining a public URL', function () {
    Sanctum::actingAs($user = User::factory()->create());
    $this->postJson('/api/v1/user/profile/avatar', ['photo' => UploadedFile::fake()->image('photo.jpg')])->assertOk();
    $key = $user->profile()->first()->avatar_object_key;
    $user->forceDelete();
    $this->artisan('avatars:cleanup')->assertSuccessful();
    Storage::disk('avatar_test')->assertMissing($key);
});

it('rejects an account changed while the picker was open', function () {
    Sanctum::actingAs($user = User::factory()->create());
    $this->putJson('/api/v1/user/profile/avatar', ['preset_id' => 'open-peeps-01', 'expected_user_id' => $user->id + 1])->assertConflict();
    $this->getJson('/api/v1/user/profile/avatar')->assertJsonPath('data.avatar_url', null);
});

it('failed storage writes preserve the active picture', function () {
    Sanctum::actingAs(User::factory()->create());
    $this->putJson('/api/v1/user/profile/avatar', ['preset_id' => 'open-peeps-06'])->assertOk();
    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('put')->andThrow(new RuntimeException('Unavailable'));
    Storage::shouldReceive('disk')->with('avatar_test')->andReturn($disk);
    $this->postJson('/api/v1/user/profile/avatar', ['photo' => UploadedFile::fake()->image('photo.jpg')])->assertStatus(503);
    $this->getJson('/api/v1/user/profile/avatar')->assertJsonPath('data.avatar_url', 'asset:open-peeps-06');
});

it('retains failed cleanup for a later scheduled retry', function () {
    DB::table('avatar_photo_deletions')->insert(['disk' => 'avatar_test', 'object_key' => 'avatars/test.jpg', 'created_at' => now(), 'updated_at' => now()]);
    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('delete')->andReturn(false);
    Storage::shouldReceive('disk')->with('avatar_test')->andReturn($disk);
    $this->artisan('avatars:cleanup')->assertSuccessful();
    expect(DB::table('avatar_photo_deletions')->value('attempts'))->toBe(1);
});

it('checks signed storage access without leaving a verification object', function () {
    Http::fake(function ($request) {
        return str_contains($request->url(), '?expires=')
            ? Http::response('cozy-avatar-check', 200)
            : Http::response('', 403);
    });
    $this->artisan('avatars:check-storage')->assertSuccessful();
    expect(Storage::disk('avatar_test')->allFiles())->toBeEmpty();
});
