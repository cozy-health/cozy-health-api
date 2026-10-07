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
        ->assertJsonPath('has_provider', false)
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

it('returns the full pending connection without claiming a verified provider', function () {
    $user = User::factory()->create();
    $provider = createProvider(['phone' => '+234123456789']);
    Sanctum::actingAs($user);
    ProviderConnection::create(['user_id' => $user->id, 'provider_id' => $provider->id, 'provider_email' => $provider->email]);
    $this->getJson('/api/v1/provider')->assertOk()
        ->assertJsonPath('has_provider', false)
        ->assertJsonPath('provider.status', 'pending')
        ->assertJsonPath('provider.provider_name', $provider->name)
        ->assertJsonPath('provider.provider_email', $provider->email)
        ->assertJsonPath('provider.provider_phone', $provider->phone)
        ->assertJsonPath('provider.provider.email', $provider->email)
        ->assertJsonMissingPath('provider.verification_code');
});

it('returns the full verified connection with stored contact details', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    ProviderConnection::create(['user_id' => $user->id, 'provider_email' => 'therapist@example.com',
        'provider_name' => 'Therapist', 'provider_phone' => '+234123456789', 'verified_at' => now()]);
    $this->getJson('/api/v1/provider')->assertOk()->assertJsonPath('has_provider', true)
        ->assertJsonPath('provider.status', 'verified')
        ->assertJsonPath('provider.provider_name', 'Therapist')
        ->assertJsonPath('provider.provider_email', 'therapist@example.com')
        ->assertJsonPath('provider.provider_phone', '+234123456789');
});

it('accepts mobile email and optional contact details and returns the pending provider', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $this->postJson('/api/v1/provider/request', ['provider_email' => 'therapist@example.com',
        'provider_name' => 'Therapist', 'provider_phone' => '+234123456789'])
        ->assertOk()->assertJsonPath('success', true)->assertJsonPath('message', 'Verification code created.')
        ->assertJsonPath('status', 'pending')->assertJsonPath('has_provider', false)
        ->assertJsonPath('provider.status', 'pending')->assertJsonPath('provider.provider_name', 'Therapist')
        ->assertJsonPath('provider.provider_email', 'therapist@example.com')
        ->assertJsonPath('provider.provider_phone', '+234123456789')
        ->assertJsonPath('data.expires_in_minutes', 15)->assertJsonMissingPath('provider.verification_code');
    $this->assertDatabaseHas('provider_connections', ['user_id' => $user->id,
        'provider_email' => 'therapist@example.com', 'provider_name' => 'Therapist', 'provider_phone' => '+234123456789']);
    $this->getJson('/api/v1/provider')->assertOk()->assertJsonPath('has_provider', false)
        ->assertJsonPath('provider.status', 'pending');
});

it('prefers provider email when both validated aliases are supplied', function () {
    Sanctum::actingAs(User::factory()->create());
    $this->postJson('/api/v1/provider/request', ['provider_email' => 'mobile@example.com', 'email' => 'legacy@example.com'])
        ->assertOk()->assertJsonPath('provider.provider_email', 'mobile@example.com');
});

it('accepts legacy email and derives optional contact fields from the linked provider', function () {
    $user = User::factory()->create();
    $provider = createProvider(['phone' => '+234123456789']);
    Sanctum::actingAs($user);
    $this->postJson('/api/v1/provider/request', ['email' => $provider->email])
        ->assertOk()->assertJsonPath('provider.status', 'pending')->assertJsonPath('has_provider', false)
        ->assertJsonPath('provider.provider_name', $provider->name)
        ->assertJsonPath('provider.provider_email', $provider->email)
        ->assertJsonPath('provider.provider_phone', $provider->phone)
        ->assertJsonPath('data.expires_in_minutes', 15);
});

it('stores a complete bcrypt hash and verifies the generated code through the endpoint', function () {
    $this->app['env'] = 'local'; // Existing development-only code response; no production disclosure.
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $response = $this->postJson('/api/v1/provider/request', ['provider_email' => 'therapist@example.com',
        'provider_name' => 'Therapist', 'provider_phone' => '+234123456789'])
        ->assertOk();
    $code = $response->json('data.development_code');
    $connection = ProviderConnection::where('user_id', $user->id)->firstOrFail();
    expect(strlen($connection->verification_code))->toBeGreaterThan(10);
    expect(Hash::check($code, $connection->verification_code))->toBeTrue();
    $this->postJson('/api/v1/provider/verify', ['code' => $code])->assertOk()
        ->assertJsonPath('success', true)->assertJsonPath('message', 'Provider verified successfully.')
        ->assertJsonPath('has_provider', true)->assertJsonPath('provider.status', 'verified')
        ->assertJsonPath('provider.provider_name', 'Therapist')
        ->assertJsonPath('provider.provider_email', 'therapist@example.com')
        ->assertJsonPath('provider.provider_phone', '+234123456789')
        ->assertJsonPath('data.id', $connection->id)
        ->assertJsonMissingPath('provider.verification_code')->assertJsonMissingPath('data.verification_code');
    $connection->refresh();
    expect($connection->verification_code)->toBeNull();
    expect($connection->verification_expires_at)->toBeNull();
    expect($connection->verified_at)->not->toBeNull();
    $this->getJson('/api/v1/provider')->assertOk()->assertJsonPath('has_provider', true);
});

it('requires a six character verification code', function () {
    Sanctum::actingAs(User::factory()->create());
    foreach ([[], ['code' => '123'], ['code' => 123456]] as $payload) {
        $this->postJson('/api/v1/provider/verify', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('code');
    }
});

it('validates both email aliases and optional field lengths', function () {
    Sanctum::actingAs(User::factory()->create());
    $this->postJson('/api/v1/provider/request', [])->assertUnprocessable()->assertJsonValidationErrors(['email', 'provider_email']);
    $this->postJson('/api/v1/provider/request', ['provider_email' => 'invalid', 'provider_name' => str_repeat('x', 192),
        'provider_phone' => str_repeat('1', 33)])->assertUnprocessable()
        ->assertJsonValidationErrors(['provider_email', 'provider_name', 'provider_phone']);
    $this->postJson('/api/v1/provider/request', ['email' => 'invalid'])
        ->assertUnprocessable()->assertJsonValidationErrors('email');
});

it('rejects expired codes without verifying the connection', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $connection = ProviderConnection::create(['user_id' => $user->id, 'provider_email' => 'therapist@example.com',
        'verification_code' => Hash::make('123456'), 'verification_expires_at' => now()->subMinute()]);
    $this->postJson('/api/v1/provider/verify', ['code' => '123456'])->assertUnprocessable();
    expect($connection->fresh()->verified_at)->toBeNull();
});

it('returns the empty mobile state after deleting the connection', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    ProviderConnection::create(['user_id' => $user->id, 'provider_email' => 'therapist@example.com', 'verified_at' => now()]);
    $this->deleteJson('/api/v1/provider')->assertExactJson(['success' => true,
        'message' => 'Provider connection removed.', 'data' => null]);
    $this->getJson('/api/v1/provider')->assertExactJson(['has_provider' => false, 'provider' => null]);
});
