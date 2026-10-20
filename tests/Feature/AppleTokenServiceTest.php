<?php

use App\Models\User;
use App\Services\Apple\AppleTokenVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Tests\Support\AppleSigningKey;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    Mail::fake();
    Http::preventStrayRequests();
    AppleSigningKey::configure();
    $this->identity = ['sub' => 'apple-grant-subject', 'aud' => 'bundle-id', 'email' => 'apple@example.com',
        'email_verified' => true, 'is_private_email' => false];
});

function mockAppleGrantIdentities(array $original, ?array $exchanged = null): void
{
    test()->mock(AppleTokenVerifier::class, function ($mock) use ($original, $exchanged) {
        $mock->shouldReceive('verify')->once()->with('original-identity')->andReturn($original);
        if ($exchanged !== null) {
            $mock->shouldReceive('verify')->once()->with('exchanged-identity')->andReturn($exchanged);
        }
    });
}

it('apple sign in exchanges the code and stores an encrypted hidden refresh token', function () {
    mockAppleGrantIdentities($this->identity, $this->identity);
    $transactionLevel = DB::connection()->transactionLevel();
    Http::fake(['appleid.apple.com/auth/token' => function ($request) use ($transactionLevel) {
        expect(DB::connection()->transactionLevel())->toBe($transactionLevel);
        expect($request['grant_type'])->toBe('authorization_code')->and($request['code'])->toBe('single-use-code')
            ->and($request['client_id'])->toBe('bundle-id');

        return Http::response(['refresh_token' => 'refresh-token-secret', 'id_token' => 'exchanged-identity']);
    }]);
    $response = $this->postJson('/api/v1/auth/apple', [
        'identity_token' => 'original-identity', 'authorization_code' => 'single-use-code',
    ])->assertOk();
    $user = User::firstOrFail();
    expect($user->apple_refresh_token)->toBe('refresh-token-secret')->and($user->apple_token_client_id)->toBe('bundle-id');
    $ciphertext = DB::table('users')->where('id', $user->id)->value('apple_refresh_token');
    expect($ciphertext)->not->toBe('refresh-token-secret')->and(Crypt::decryptString($ciphertext))->toBe('refresh-token-secret');
    foreach (['apple_refresh_token', 'apple_token_client_id', 'apple_linked_at', 'google_linked_at'] as $field) {
        $response->assertJsonMissingPath('data.user.'.$field);
    }
    expect(DB::getSchemaBuilder()->hasColumn('users', 'apple_authorization_code'))->toBeFalse();
});

it('apple sign in binds the authorization code to the verified subject and audience', function ($field, $value) {
    mockAppleGrantIdentities($this->identity, array_replace($this->identity, [$field => $value]));
    Http::fake(['appleid.apple.com/auth/token' => Http::response([
        'refresh_token' => 'other-person-token', 'id_token' => 'exchanged-identity',
    ])]);
    $this->postJson('/api/v1/auth/apple', ['identity_token' => 'original-identity', 'authorization_code' => 'wrong-code'])
        ->assertUnauthorized();
    $this->assertDatabaseCount('users', 0);
    $this->assertDatabaseCount('personal_access_tokens', 0);
})->with([['sub', 'other-person'], ['aud', 'service-id']]);

it('apple sign in rejects an invalid consumed authorization code without creating an account', function () {
    mockAppleGrantIdentities($this->identity);
    Http::fake(['appleid.apple.com/auth/token' => Http::response(['error' => 'invalid_grant'], 400)]);
    $this->postJson('/api/v1/auth/apple', ['identity_token' => 'original-identity', 'authorization_code' => 'consumed-code'])
        ->assertUnauthorized();
    $this->assertDatabaseCount('users', 0);
});

it('apple exchange failures do not create an account or leak provider responses', function ($response) {
    mockAppleGrantIdentities($this->identity);
    Http::fake(['appleid.apple.com/auth/token' => $response]);
    $this->postJson('/api/v1/auth/apple', ['identity_token' => 'original-identity', 'authorization_code' => 'code'])
        ->assertStatus(503)->assertJsonPath('message', 'Apple authorization is temporarily unavailable.');
    $this->assertDatabaseCount('users', 0);
})->with([
    'provider outage' => fn () => Http::response(['error' => 'private-provider-details'], 500),
    'missing refresh token' => fn () => Http::response(['id_token' => 'exchanged-identity']),
    'connection failure' => fn () => Http::failedConnection(),
]);

it('missing signing configuration warns and preserves existing identity-only login', function () {
    config(['services.apple.team_id' => null]);
    mockAppleGrantIdentities($this->identity);
    Log::spy();
    $this->postJson('/api/v1/auth/apple', ['identity_token' => 'original-identity', 'authorization_code' => 'code'])->assertOk();
    expect(User::firstOrFail()->apple_refresh_token)->toBeNull();
    Http::assertNothingSent();
    Log::shouldHaveReceived('warning')->once();
});

it('a later apple sign in without a code preserves the previously stored grant', function () {
    $user = User::factory()->create(['apple_sub' => $this->identity['sub'], 'email' => $this->identity['email']]);
    $user->apple_refresh_token = 'previous-refresh-token';
    $user->apple_token_client_id = 'bundle-id';
    $user->save();
    mockAppleGrantIdentities($this->identity);
    $this->postJson('/api/v1/auth/apple', ['identity_token' => 'original-identity'])->assertOk();
    expect($user->fresh()->apple_refresh_token)->toBe('previous-refresh-token');
    Http::assertNothingSent();
});

it('apple grant capture can use web service ID and configured redirect URI', function () {
    $identity = array_replace($this->identity, ['aud' => 'service-id']);
    config(['services.apple.redirect_uri' => 'https://example.com/apple/callback']);
    mockAppleGrantIdentities($identity, $identity);
    Http::fake(['appleid.apple.com/auth/token' => function ($request) {
        expect($request['client_id'])->toBe('service-id')->and($request['redirect_uri'])->toBe('https://example.com/apple/callback');

        return Http::response(['refresh_token' => 'web-refresh-token', 'id_token' => 'exchanged-identity']);
    }]);
    $this->postJson('/api/v1/auth/apple', ['identity_token' => 'original-identity', 'authorization_code' => 'web-code'])->assertOk();
    expect(User::firstOrFail()->apple_token_client_id)->toBe('service-id');
});

it('apple authorization code is validated as a bounded string', function () {
    $this->postJson('/api/v1/auth/apple', ['identity_token' => 'original-identity', 'authorization_code' => str_repeat('x', 4097)])
        ->assertUnprocessable()->assertJsonValidationErrors('authorization_code');
    Http::assertNothingSent();
});
