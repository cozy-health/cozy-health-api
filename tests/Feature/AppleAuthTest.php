<?php

use App\Mail\WelcomeMail;
use App\Models\User;
use App\Services\Apple\AppleTokenVerifier;
use App\Services\Apple\InvalidAppleTokenException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    Mail::fake();
    Http::preventStrayRequests();
    $this->appleClaims = [
        'sub' => 'apple-subject-123',
        'email' => 'apple-user@gmail.com',
        'email_verified' => true,
        'is_private_email' => false,
    ];
});

function mockAppleAuthClaims(array $claims): void
{
    test()->mock(AppleTokenVerifier::class, function ($mock) use ($claims) {
        $mock->shouldReceive('verify')->once()->with('test-id-token')->andReturn($claims);
    });
}

it('apple signin requires identity token', function () {
    $this->postJson('/api/v1/auth/apple', [])
        ->assertUnprocessable()->assertJsonValidationErrors('identity_token');
    $this->assertDatabaseCount('users', 0);
});

it('apple signin creates a new user and registration profile defaults', function () {
    mockAppleAuthClaims($this->appleClaims);
    $response = $this->postJson('/api/v1/auth/apple', [
        'identity_token' => 'test-id-token', 'device_name' => 'apple-test-device',
    ])->assertOk()->assertJsonPath('success', true)
        ->assertJsonPath('data.user.name', 'Cozy User')
        ->assertJsonPath('data.user.email', 'apple-user@gmail.com');

    $user = User::firstOrFail();
    expect($user->apple_sub)->toBe('apple-subject-123')
        ->and($user->apple_linked_at)->not->toBeNull()
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->timezone)->toBe('UTC')
        ->and($user->is_active)->toBeTrue()
        ->and(Hash::check('password', $user->password))->toBeFalse();
    $this->assertDatabaseCount('user_profiles', 1);
    $this->assertDatabaseHas('user_profiles', [
        'user_id' => $user->id, 'name' => 'Cozy User',
        'theme' => 'system', 'accent_color' => '#0460D8',
        'daily_checkin_time' => '09:00', 'marketing_notifications' => false,
    ]);
    $this->assertDatabaseHas('personal_access_tokens', ['name' => 'apple-test-device']);
    $this->withToken($response->json('data.token'))->getJson('/api/v1/me')->assertOk();
    Mail::assertSent(WelcomeMail::class);
});

it('apple signin links an existing verified email account', function () {
    $user = User::factory()->create(['email' => $this->appleClaims['email'], 'email_verified_at' => null]);
    $password = $user->password;
    mockAppleAuthClaims($this->appleClaims);
    $this->postJson('/api/v1/auth/apple', ['identity_token' => 'test-id-token'])
        ->assertOk()->assertJsonPath('data.user.id', $user->id);
    expect($user->fresh()->apple_sub)->toBe($this->appleClaims['sub'])
        ->and($user->fresh()->email_verified_at)->not->toBeNull()
        ->and($user->fresh()->password)->toBe($password);
    $this->assertDatabaseCount('users', 1);
    Mail::assertNothingSent();
});

it('apple signin returns an existing user by apple sub even when email changes', function () {
    $user = User::factory()->create([
        'apple_sub' => $this->appleClaims['sub'], 'apple_linked_at' => now()->subDay(),
        'email' => 'original@gmail.com',
    ]);
    $linkedAt = $user->apple_linked_at;
    mockAppleAuthClaims($this->appleClaims);
    $this->postJson('/api/v1/auth/apple', ['identity_token' => 'test-id-token'])
        ->assertOk()->assertJsonPath('data.user.id', $user->id)
        ->assertJsonPath('data.user.email', 'original@gmail.com');
    expect($user->fresh()->apple_linked_at->equalTo($linkedAt))->toBeTrue();
    $this->assertDatabaseCount('users', 1);
});

it('apple signin rejects a disabled account without linking or issuing a token', function ($alreadyLinked) {
    $user = User::factory()->create([
        'email' => $this->appleClaims['email'], 'is_active' => false,
        'apple_sub' => $alreadyLinked ? $this->appleClaims['sub'] : null,
    ]);
    mockAppleAuthClaims($this->appleClaims);
    $this->postJson('/api/v1/auth/apple', ['identity_token' => 'test-id-token'])
        ->assertForbidden()->assertExactJson(['success' => false, 'message' => 'This account has been disabled.']);
    expect($user->fresh()->apple_sub)->toBe($alreadyLinked ? $this->appleClaims['sub'] : null);
    $this->assertDatabaseCount('personal_access_tokens', 0);
})->with([false, true]);

it('apple signin rejects an invalid token', function () {
    $this->mock(AppleTokenVerifier::class, function ($mock) {
        $mock->shouldReceive('verify')->once()->andThrow(new InvalidAppleTokenException);
    });
    $this->postJson('/api/v1/auth/apple', ['identity_token' => 'invalid'])
        ->assertUnauthorized()->assertExactJson(['success' => false, 'message' => 'Invalid Apple identity token.']);
    $this->assertDatabaseCount('users', 0);
    $this->assertDatabaseCount('personal_access_tokens', 0);
});

it('apple signin returns the same envelope and user serialization as login', function () {
    $user = User::factory()->create([
        'apple_sub' => $this->appleClaims['sub'], 'email' => $this->appleClaims['email'],
    ]);
    mockAppleAuthClaims($this->appleClaims);
    $apple = $this->postJson('/api/v1/auth/apple', ['identity_token' => 'test-id-token'])->assertOk();
    $login = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])->assertOk();
    expect(array_keys($apple->json()))->toBe(array_keys($login->json()))
        ->and(array_keys($apple->json('data')))->toBe(array_keys($login->json('data')))
        ->and($apple->json('message'))->toBe($login->json('message'))
        ->and($apple->json('data.user'))->toBe($login->json('data.user'));
    $apple->assertJsonMissingPath('data.user.apple_sub')->assertJsonMissingPath('data.user.google_sub')
        ->assertJsonMissingPath('data.user.password')->assertJsonMissingPath('data.user.remember_token');
});

it('apple signin refuses to overwrite a different apple identity', function () {
    $user = User::factory()->create(['email' => $this->appleClaims['email'], 'apple_sub' => 'different-sub']);
    mockAppleAuthClaims($this->appleClaims);
    $this->postJson('/api/v1/auth/apple', ['identity_token' => 'test-id-token'])->assertConflict();
    expect($user->fresh()->apple_sub)->toBe('different-sub');
    $this->assertDatabaseCount('personal_access_tokens', 0);
});

it('apple signin does not link an unverified email to an existing account', function () {
    $user = User::factory()->create(['email' => $this->appleClaims['email']]);
    mockAppleAuthClaims(array_replace($this->appleClaims, ['email_verified' => false]));
    $this->postJson('/api/v1/auth/apple', ['identity_token' => 'test-id-token'])->assertConflict();
    expect($user->fresh()->apple_sub)->toBeNull();
    $this->assertDatabaseCount('personal_access_tokens', 0);
});

it('apple signin leaves new unverified email unverified and supplies a fallback name', function () {
    mockAppleAuthClaims(array_replace($this->appleClaims, ['email_verified' => false]));
    $this->postJson('/api/v1/auth/apple', ['identity_token' => 'test-id-token'])
        ->assertOk()->assertJsonPath('data.user.name', 'Cozy User')->assertJsonPath('data.user.email_verified_at', null);
    $this->assertDatabaseHas('personal_access_tokens', ['name' => 'cozy-mobile']);
});

it('apple signin validates device name and token size', function () {
    $this->postJson('/api/v1/auth/apple', ['identity_token' => str_repeat('x', 16385), 'device_name' => str_repeat('x', 121)])
        ->assertUnprocessable()->assertJsonValidationErrors(['identity_token', 'device_name']);
});

it('apple signin throttles after ten attempts', function () {
    for ($attempt = 0; $attempt < 10; $attempt++) {
        $this->postJson('/api/v1/auth/apple', [])->assertUnprocessable();
    }
    $this->postJson('/api/v1/auth/apple', [])->assertTooManyRequests()->assertHeader('Retry-After');
});

it('apple signin stores the first authorization full name in user and profile', function () {
    mockAppleAuthClaims($this->appleClaims);
    $this->postJson('/api/v1/auth/apple', ['identity_token' => 'test-id-token', 'full_name' => '  Apple Person  '])
        ->assertOk()->assertJsonPath('data.user.name', 'Apple Person');
    $this->assertDatabaseHas('user_profiles', ['name' => 'Apple Person']);
});

it('apple signin defaults a missing null or blank name', function ($name) {
    mockAppleAuthClaims($this->appleClaims);
    $this->postJson('/api/v1/auth/apple', ['identity_token' => 'test-id-token', 'full_name' => $name])
        ->assertOk()->assertJsonPath('data.user.name', 'Cozy User');
})->with([null, '', '   ']);

it('apple signin preserves existing names and first linking timestamp on later authorizations', function () {
    $user = User::factory()->create([
        'email' => $this->appleClaims['email'], 'apple_sub' => $this->appleClaims['sub'],
        'name' => 'Original Name', 'apple_linked_at' => now()->subDay(),
    ]);
    $linkedAt = $user->apple_linked_at;
    mockAppleAuthClaims($this->appleClaims);
    $this->postJson('/api/v1/auth/apple', ['identity_token' => 'test-id-token', 'full_name' => 'Replacement Name'])
        ->assertOk()->assertJsonPath('data.user.name', 'Original Name');
    expect($user->fresh()->apple_linked_at->equalTo($linkedAt))->toBeTrue();
});

it('apple signin creates a new account with private relay email', function () {
    $claims = array_replace($this->appleClaims, ['email' => 'relay@privaterelay.appleid.com', 'is_private_email' => true]);
    mockAppleAuthClaims($claims);
    $this->postJson('/api/v1/auth/apple', ['identity_token' => 'test-id-token'])
        ->assertOk()->assertJsonPath('data.user.email', $claims['email']);
    expect(User::firstOrFail()->apple_sub)->toBe($claims['sub']);
});

it('apple signin does not link private email collisions', function ($email, $private) {
    $user = User::factory()->create(['email' => $email]);
    mockAppleAuthClaims(array_replace($this->appleClaims, ['email' => $email, 'is_private_email' => $private]));
    $this->postJson('/api/v1/auth/apple', ['identity_token' => 'test-id-token'])->assertConflict();
    expect($user->fresh()->apple_sub)->toBeNull();
    $this->assertDatabaseCount('users', 1);
    $this->assertDatabaseCount('personal_access_tokens', 0);
})->with([
    'relay claim' => ['relay@privaterelay.appleid.com', true],
    'relay suffix alone' => ['relay@PRIVATERELAY.APPLEID.COM', false],
    'private claim alone' => ['private@example.com', true],
]);

it('apple signin accepts an existing private relay identity by subject', function () {
    $user = User::factory()->create(['email' => 'relay@privaterelay.appleid.com', 'apple_sub' => $this->appleClaims['sub']]);
    mockAppleAuthClaims(array_replace($this->appleClaims, ['email' => $user->email, 'is_private_email' => true]));
    $this->postJson('/api/v1/auth/apple', ['identity_token' => 'test-id-token'])
        ->assertOk()->assertJsonPath('data.user.id', $user->id);
    $this->assertDatabaseCount('users', 1);
});

it('apple signin returns 503 when unconfigured', function () {
    $this->mock(AppleTokenVerifier::class, function ($mock) {
        $mock->shouldReceive('verify')->once()->andThrow(new HttpException(
            503, 'Apple Sign In is not configured.'
        ));
    });
    $this->postJson('/api/v1/auth/apple', ['identity_token' => 'test-id-token'])
        ->assertStatus(503)->assertExactJson(['success' => false, 'message' => 'Apple Sign In is not configured.']);
    $this->assertDatabaseCount('users', 0);
});

it('apple signin validates first authorization full name', function () {
    $this->postJson('/api/v1/auth/apple', ['identity_token' => 'test-id-token', 'full_name' => str_repeat('x', 256)])
        ->assertUnprocessable()->assertJsonValidationErrors('full_name');
});
