<?php

use App\Models\User;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\Support\AppleSigningKey;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    Http::preventStrayRequests();
    AppleSigningKey::configure();
});

function appleDeletionUser(): User
{
    $user = User::factory()->create(['apple_sub' => 'apple-delete', 'apple_linked_at' => now(),
        'google_sub' => 'google-delete', 'google_linked_at' => now()]);
    $user->apple_refresh_token = 'stored-refresh-token';
    $user->apple_token_client_id = 'bundle-id';
    $user->save();

    return $user;
}

it('account deletion requires authentication', function () {
    $this->deleteJson('/api/v1/account')->assertUnauthorized();
    Http::assertNothingSent();
});

it('deleting account clears provider identities and all sanctum tokens before removal', function () {
    $user = appleDeletionUser();
    Http::fake(['appleid.apple.com/auth/revoke' => Http::response('', 200)]);
    $current = $user->createToken('current')->plainTextToken;
    $user->createToken('another-device');
    $deletingUser = null;
    User::deleting(function ($model) use (&$deletingUser) {
        $deletingUser = $model;
    });
    $this->withToken($current)->deleteJson('/api/v1/account')
        ->assertOk()->assertExactJson(['success' => true, 'message' => 'Account deleted successfully.', 'data' => null]);
    expect($deletingUser->google_sub)->toBeNull()->and($deletingUser->apple_sub)->toBeNull()
        ->and($deletingUser->google_linked_at)->toBeNull()->and($deletingUser->apple_linked_at)->toBeNull()
        ->and($deletingUser->apple_refresh_token)->toBeNull()->and($deletingUser->apple_token_client_id)->toBeNull();
    $this->assertDatabaseMissing('users', ['id' => $user->id]);
    $this->assertDatabaseCount('personal_access_tokens', 0);
    $this->app['auth']->forgetGuards();
    $this->withToken($current)->getJson('/api/v1/me')->assertUnauthorized();
});

it('deleting account attempts apple refresh token revocation with correct ES256 client secret', function () {
    $user = appleDeletionUser();
    $outerTransactionLevel = DB::connection()->transactionLevel();
    Http::fake(['appleid.apple.com/auth/revoke' => function ($request) use ($outerTransactionLevel) {
        expect(DB::connection()->transactionLevel())->toBe($outerTransactionLevel);
        expect($request['client_id'])->toBe('bundle-id')->and($request['token'])->toBe('stored-refresh-token')
            ->and($request['token_type_hint'])->toBe('refresh_token');
        $headers = new stdClass;
        $secret = JWT::decode($request['client_secret'], new Key(AppleSigningKey::material()['public'], 'ES256'), $headers);
        expect($headers->kid)->toBe('TEST_KEY')->and($headers->alg)->toBe('ES256')
            ->and($secret->iss)->toBe('TEST_TEAM')->and($secret->sub)->toBe('bundle-id')
            ->and($secret->aud)->toBe('https://appleid.apple.com')
            ->and($secret->exp - $secret->iat)->toBe(180 * 24 * 60 * 60);

        return Http::response('', 200);
    }]);
    $this->withToken($user->createToken('current')->plainTextToken)->deleteJson('/api/v1/account')->assertOk();
    Http::assertSentCount(1);
});

it('account deletion continues when apple revocation fails', function ($response) {
    $user = appleDeletionUser();
    Http::fake(['appleid.apple.com/auth/revoke' => $response]);
    Log::spy();
    $this->withToken($user->createToken('current')->plainTextToken)->deleteJson('/api/v1/account')->assertOk();
    $this->assertDatabaseMissing('users', ['id' => $user->id]);
    $this->assertDatabaseCount('personal_access_tokens', 0);
    Log::shouldHaveReceived('warning')->once();
})->with([
    'provider rejects token' => fn () => Http::response(['error' => 'private-provider-details'], 400),
    'provider server error' => fn () => Http::response([], 500),
    'connection failure' => fn () => Http::failedConnection(),
]);

it('account deletion continues with missing apple credentials or token', function ($missing) {
    $user = appleDeletionUser();
    if ($missing === 'token') {
        $user->apple_refresh_token = null;
        $user->save();
    } else {
        config(['services.apple.'.$missing => null]);
    }
    Log::spy();
    $this->withToken($user->createToken('current')->plainTextToken)->deleteJson('/api/v1/account')->assertOk();
    $this->assertDatabaseMissing('users', ['id' => $user->id]);
    Http::assertNothingSent();
    Log::shouldHaveReceived('warning')->once();
})->with(['team_id', 'key_id', 'private_key', 'token']);

it('account deletion continues with invalid signing key or undecryptable token', function ($invalid) {
    $user = appleDeletionUser();
    if ($invalid === 'signing key') {
        config(['services.apple.private_key' => 'invalid-key']);
    } else {
        DB::table('users')->where('id', $user->id)->update(['apple_refresh_token' => 'unreadable-ciphertext']);
    }
    Log::spy();
    $this->withToken($user->createToken('current')->plainTextToken)->deleteJson('/api/v1/account')->assertOk();
    $this->assertDatabaseMissing('users', ['id' => $user->id]);
    Log::shouldHaveReceived('warning')->once();
})->with(['signing key', 'ciphertext']);

it('deletes non provider account data without provider calls and preserves other users', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $otherToken = $other->createToken('other')->plainTextToken;
    $user->profile()->create(['id' => (string) Str::uuid(), 'name' => $user->name, 'email' => $user->email]);
    DB::table('password_reset_tokens')->insert(['email' => $user->email, 'token' => 'test-hash']);
    DB::table('password_reset_tokens')->insert(['email' => $other->email, 'token' => 'other-test-hash']);
    $this->withToken($user->createToken('current')->plainTextToken)
        ->deleteJson('/api/v1/account', ['user_id' => $other->id])->assertOk();
    $this->assertDatabaseMissing('users', ['id' => $user->id]);
    $this->assertDatabaseMissing('user_profiles', ['user_id' => $user->id]);
    $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
    $this->assertDatabaseHas('users', ['id' => $other->id]);
    $this->assertDatabaseHas('password_reset_tokens', ['email' => $other->email]);
    Http::assertNothingSent();
    $this->app['auth']->forgetGuards();
    $this->withToken($otherToken)->getJson('/api/v1/me')->assertOk();
});
