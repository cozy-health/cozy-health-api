<?php

use App\Models\Provider;
use App\Models\ProviderConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function createProvider(array $attributes = []): Provider
{
    return Provider::create(array_merge([
        'name' => 'Dr. Avery Stone',
        'email' => 'avery@example.com',
        'phone' => null,
        'specialty' => 'Therapist',
        'license_number' => 'LIC-12345',
        'bio' => null,
        'is_verified' => true,
        'is_active' => true,
    ], $attributes));
}

it('show returns null when no provider linked', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $this->getJson('/api/v1/provider')
        ->assertOk()
        ->assertJsonPath('provider', null);
});

it('request code creates a verification request', function () {
    $user = User::factory()->create();
    $provider = createProvider();
    Sanctum::actingAs($user);

    $this->postJson('/api/v1/provider/request', [
        'email' => $provider->email,
    ])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Verification code created.')
        ->assertJsonPath('data.expires_in_minutes', 15);

    $connection = ProviderConnection::where('user_id', $user->id)->first();

    expect($connection)->not->toBeNull();
    expect($connection->provider_id)->toBe($provider->id);
    expect($connection->provider_email)->toBe($provider->email);
    expect($connection->verification_code)->not->toBeNull();
    expect($connection->verification_expires_at)->not->toBeNull();
    expect($connection->verified_at)->toBeNull();
});

it('verify links provider when code matches', function () {
    $user = User::factory()->create();
    $provider = createProvider();
    Sanctum::actingAs($user);

    ProviderConnection::create([
        'user_id' => $user->id,
        'provider_id' => $provider->id,
        'provider_email' => $provider->email,
        'verification_code' => Hash::make('123456'),
        'verification_expires_at' => now()->addMinutes(15),
        'verified_at' => null,
    ]);

    $this->postJson('/api/v1/provider/verify', [
        'code' => '123456',
    ])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Provider verified successfully.')
        ->assertJsonPath('data.provider.email', $provider->email);

    $connection = $user->providerConnection()->first();

    expect($connection->verification_code)->toBeNull();
    expect($connection->verification_expires_at)->toBeNull();
    expect($connection->verified_at)->not->toBeNull();
});

it('verify fails when code does not match', function () {
    $user = User::factory()->create();
    $provider = createProvider();
    Sanctum::actingAs($user);

    ProviderConnection::create([
        'user_id' => $user->id,
        'provider_id' => $provider->id,
        'provider_email' => $provider->email,
        'verification_code' => Hash::make('123456'),
        'verification_expires_at' => now()->addMinutes(15),
        'verified_at' => null,
    ]);

    $this->postJson('/api/v1/provider/verify', [
        'code' => '654321',
    ])
        ->assertUnprocessable();

    expect($user->providerConnection()->first()->verified_at)->toBeNull();
});

it('destroy unlinks provider', function () {
    $user = User::factory()->create();
    $provider = createProvider();
    Sanctum::actingAs($user);

    ProviderConnection::create([
        'user_id' => $user->id,
        'provider_id' => $provider->id,
        'provider_email' => $provider->email,
        'verified_at' => now(),
    ]);

    $this->deleteJson('/api/v1/provider')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Provider connection removed.');

    expect($user->providerConnection()->exists())->toBeFalse();
});

it('unauthenticated returns 401', function () {
    $this->getJson('/api/v1/provider')
        ->assertUnauthorized();
});
