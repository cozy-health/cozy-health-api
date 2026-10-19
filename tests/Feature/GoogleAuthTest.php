<?php

use App\Mail\WelcomeMail;
use App\Models\User;
use App\Services\Google\GoogleTokenVerifier;
use App\Services\Google\InvalidGoogleTokenException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    Mail::fake();
    Http::preventStrayRequests();
    $this->googleClaims = [
        'sub' => 'google-subject-123',
        'email' => 'google-user@gmail.com',
        'email_verified' => true,
        'name' => 'Google User',
        'picture' => 'https://example.com/avatar.png',
    ];
});

function mockGoogleAuthClaims(array $claims): void
{
    test()->mock(GoogleTokenVerifier::class, function ($mock) use ($claims) {
        $mock->shouldReceive('verify')->once()->with('test-id-token')->andReturn($claims);
    });
}

it('google signin requires id token', function () {
    $this->postJson('/api/v1/auth/google', [])
        ->assertUnprocessable()->assertJsonValidationErrors('id_token');
    $this->assertDatabaseCount('users', 0);
});

it('google signin creates a new user and registration profile defaults', function () {
    mockGoogleAuthClaims($this->googleClaims);
    $response = $this->postJson('/api/v1/auth/google', [
        'id_token' => 'test-id-token', 'device_name' => 'google-test-device',
    ])->assertOk()->assertJsonPath('success', true)->assertJsonPath('data.is_new_user', true)
        ->assertJsonPath('data.user.name', 'Google User')
        ->assertJsonPath('data.user.email', 'google-user@gmail.com');

    $user = User::firstOrFail();
    expect($user->google_sub)->toBe('google-subject-123')
        ->and($user->google_linked_at)->not->toBeNull()
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->timezone)->toBe('UTC')
        ->and($user->is_active)->toBeTrue()
        ->and(Hash::check('password', $user->password))->toBeFalse();
    $this->assertDatabaseCount('user_profiles', 1);
    $this->assertDatabaseHas('user_profiles', [
        'user_id' => $user->id, 'name' => 'Google User',
        'theme' => 'system', 'accent_color' => '#0460D8',
        'daily_checkin_time' => '09:00', 'marketing_notifications' => false,
    ]);
    $this->assertDatabaseHas('personal_access_tokens', ['name' => 'google-test-device']);
    $this->withToken($response->json('data.token'))->getJson('/api/v1/me')->assertOk();
    Mail::assertQueued(WelcomeMail::class);
});

it('google signin links an existing verified email account', function () {
    $user = User::factory()->create(['email' => $this->googleClaims['email'], 'email_verified_at' => null]);
    $password = $user->password;
    mockGoogleAuthClaims($this->googleClaims);
    $this->postJson('/api/v1/auth/google', ['id_token' => 'test-id-token'])
        ->assertOk()->assertJsonPath('data.is_new_user', false)->assertJsonPath('data.user.id', $user->id);
    expect($user->fresh()->google_sub)->toBe($this->googleClaims['sub'])
        ->and($user->fresh()->email_verified_at)->not->toBeNull()
        ->and($user->fresh()->password)->toBe($password);
    $this->assertDatabaseCount('users', 1);
    Mail::assertNothingOutgoing();
});

it('google signin returns an existing user by google sub even when email changes', function () {
    $user = User::factory()->create([
        'google_sub' => $this->googleClaims['sub'], 'google_linked_at' => now()->subDay(),
        'email' => 'original@gmail.com',
    ]);
    $linkedAt = $user->google_linked_at;
    mockGoogleAuthClaims($this->googleClaims);
    $this->postJson('/api/v1/auth/google', ['id_token' => 'test-id-token'])
        ->assertOk()->assertJsonPath('data.user.id', $user->id)
        ->assertJsonPath('data.user.email', 'original@gmail.com');
    expect($user->fresh()->google_linked_at->equalTo($linkedAt))->toBeTrue();
    $this->assertDatabaseCount('users', 1);
});

it('google signin rejects a disabled account without linking or issuing a token', function ($alreadyLinked) {
    $user = User::factory()->create([
        'email' => $this->googleClaims['email'], 'is_active' => false,
        'google_sub' => $alreadyLinked ? $this->googleClaims['sub'] : null,
    ]);
    mockGoogleAuthClaims($this->googleClaims);
    $this->postJson('/api/v1/auth/google', ['id_token' => 'test-id-token'])
        ->assertForbidden()->assertExactJson(['success' => false, 'message' => 'This account has been disabled.']);
    expect($user->fresh()->google_sub)->toBe($alreadyLinked ? $this->googleClaims['sub'] : null);
    $this->assertDatabaseCount('personal_access_tokens', 0);
})->with([false, true]);

it('google signin rejects an invalid token', function () {
    $this->mock(GoogleTokenVerifier::class, function ($mock) {
        $mock->shouldReceive('verify')->once()->andThrow(new InvalidGoogleTokenException);
    });
    $this->postJson('/api/v1/auth/google', ['id_token' => 'invalid'])
        ->assertUnauthorized()->assertExactJson(['success' => false, 'message' => 'Invalid Google ID token.']);
    $this->assertDatabaseCount('users', 0);
    $this->assertDatabaseCount('personal_access_tokens', 0);
});

it('google signin returns the same envelope and user serialization as login', function () {
    $user = User::factory()->create([
        'google_sub' => $this->googleClaims['sub'], 'email' => $this->googleClaims['email'],
    ]);
    mockGoogleAuthClaims($this->googleClaims);
    $google = $this->postJson('/api/v1/auth/google', ['id_token' => 'test-id-token'])->assertOk();
    $login = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])->assertOk();
    expect(array_keys($google->json()))->toBe(array_keys($login->json()))
        ->and(array_keys($google->json('data')))->toBe(array_keys($login->json('data')))
        ->and($google->json('message'))->toBe($login->json('message'))
        ->and($google->json('data.user'))->toBe($login->json('data.user'));
    $google->assertJsonMissingPath('data.user.google_sub')->assertJsonMissingPath('data.user.apple_sub')
        ->assertJsonMissingPath('data.user.password')->assertJsonMissingPath('data.user.remember_token');
});

it('google signin refuses to overwrite a different google identity', function () {
    $user = User::factory()->create(['email' => $this->googleClaims['email'], 'google_sub' => 'different-sub']);
    mockGoogleAuthClaims($this->googleClaims);
    $this->postJson('/api/v1/auth/google', ['id_token' => 'test-id-token'])->assertConflict();
    expect($user->fresh()->google_sub)->toBe('different-sub');
    $this->assertDatabaseCount('personal_access_tokens', 0);
});

it('google signin does not link an unverified email to an existing account', function () {
    $user = User::factory()->create(['email' => $this->googleClaims['email']]);
    mockGoogleAuthClaims(array_replace($this->googleClaims, ['email_verified' => false]));
    $this->postJson('/api/v1/auth/google', ['id_token' => 'test-id-token'])->assertConflict();
    expect($user->fresh()->google_sub)->toBeNull();
    $this->assertDatabaseCount('personal_access_tokens', 0);
});

it('google signin leaves new unverified email unverified and supplies a fallback name', function () {
    mockGoogleAuthClaims(array_replace($this->googleClaims, ['email_verified' => false, 'name' => null]));
    $this->postJson('/api/v1/auth/google', ['id_token' => 'test-id-token'])
        ->assertOk()->assertJsonPath('data.user.name', 'Cozy User')->assertJsonPath('data.user.email_verified_at', null);
    $this->assertDatabaseHas('personal_access_tokens', ['name' => 'cozy-mobile']);
});

it('google signin validates device name and token size', function () {
    $this->postJson('/api/v1/auth/google', ['id_token' => str_repeat('x', 16385), 'device_name' => str_repeat('x', 121)])
        ->assertUnprocessable()->assertJsonValidationErrors(['id_token', 'device_name']);
});

it('google signin throttles after ten attempts', function () {
    for ($attempt = 0; $attempt < 10; $attempt++) {
        $this->postJson('/api/v1/auth/google', [])->assertUnprocessable();
    }
    $this->postJson('/api/v1/auth/google', [])->assertTooManyRequests()->assertHeader('Retry-After');
});
