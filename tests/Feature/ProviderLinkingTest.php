<?php

use App\Models\User;
use App\Services\Apple\AppleTokenVerifier;
use App\Services\Google\GoogleTokenVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    Mail::fake();
    Http::preventStrayRequests();
});

function providerLinkingSignIn(string $provider, string $email, string $sub, bool $private = false): TestResponse
{
    $claims = ['sub' => $sub, 'email' => $email, 'email_verified' => true, 'name' => 'Provider User', 'is_private_email' => $private];
    $verifier = $provider === 'google' ? GoogleTokenVerifier::class : AppleTokenVerifier::class;
    test()->mock($verifier, fn ($mock) => $mock->shouldReceive('verify')->once()->andReturn($claims));

    return test()->postJson('/api/v1/auth/'.$provider, [
        $provider === 'google' ? 'id_token' : 'identity_token' => 'test-token',
    ]);
}

it('A email password user can link google without replacing the password', function () {
    $user = User::factory()->create(['email' => 'link@example.com']);
    $password = $user->password;
    providerLinkingSignIn('google', $user->email, 'google-A')->assertOk()->assertJsonPath('data.user.id', $user->id);
    expect($user->fresh()->google_sub)->toBe('google-A')->and($user->fresh()->password)->toBe($password);
    $this->assertDatabaseCount('users', 1);
});

it('B google user can add apple with the same public email', function () {
    $google = providerLinkingSignIn('google', 'both@example.com', 'google-B')->assertOk();
    providerLinkingSignIn('apple', 'both@example.com', 'apple-B')->assertOk()->assertJsonPath('data.user.id', $google->json('data.user.id'));
    $this->assertDatabaseHas('users', ['google_sub' => 'google-B', 'apple_sub' => 'apple-B']);
    $this->assertDatabaseCount('users', 1);
    $this->assertDatabaseCount('user_profiles', 1);
});

it('C google public email and apple relay create separate accounts', function () {
    $google = providerLinkingSignIn('google', 'public@example.com', 'google-C')->assertOk();
    $apple = providerLinkingSignIn('apple', 'relay@privaterelay.appleid.com', 'apple-C', true)->assertOk();
    expect($apple->json('data.user.id'))->not->toBe($google->json('data.user.id'));
    $this->assertDatabaseCount('users', 2);
});

it('D apple relay user and google public email create separate accounts', function () {
    $apple = providerLinkingSignIn('apple', 'relay@privaterelay.appleid.com', 'apple-D', true)->assertOk();
    $google = providerLinkingSignIn('google', 'public@example.com', 'google-D')->assertOk();
    expect($google->json('data.user.id'))->not->toBe($apple->json('data.user.id'));
    $this->assertDatabaseCount('users', 2);
});

it('E apple public email user can add google and both identities keep working', function () {
    $apple = providerLinkingSignIn('apple', 'both@example.com', 'apple-E')->assertOk();
    $id = $apple->json('data.user.id');
    providerLinkingSignIn('google', 'both@example.com', 'google-E')->assertOk()->assertJsonPath('data.user.id', $id);
    providerLinkingSignIn('apple', 'changed@example.com', 'apple-E')->assertOk()->assertJsonPath('data.user.id', $id);
    providerLinkingSignIn('google', 'changed@example.com', 'google-E')->assertOk()->assertJsonPath('data.user.id', $id);
    $this->assertDatabaseHas('users', ['id' => $id, 'apple_sub' => 'apple-E', 'google_sub' => 'google-E']);
    $this->assertDatabaseCount('users', 1);
});

it('an exact private relay email collision never links or creates a duplicate', function () {
    $user = User::factory()->create(['email' => 'relay@privaterelay.appleid.com']);
    providerLinkingSignIn('apple', $user->email, 'apple-collision', true)->assertConflict();
    expect($user->fresh()->apple_sub)->toBeNull();
    $this->assertDatabaseCount('users', 1);
    $this->assertDatabaseCount('personal_access_tokens', 0);
});

it('provider timestamps and grants never appear in user API responses', function () {
    $user = User::factory()->create(['google_sub' => 'hidden-sub', 'google_linked_at' => now(), 'apple_linked_at' => now()]);
    $token = $user->createToken('test')->plainTextToken;
    foreach (['/api/v1/me', '/api/v1/user/profile'] as $path) {
        $response = $this->withToken($token)->getJson($path)->assertOk();
        foreach (['google_sub', 'apple_sub', 'google_linked_at', 'apple_linked_at', 'apple_refresh_token', 'apple_token_client_id'] as $field) {
            $response->assertJsonMissingPath('data.'.$field);
        }
    }
});
