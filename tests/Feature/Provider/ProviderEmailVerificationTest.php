<?php

use App\Mail\SignInChallengeMail;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['signin_security.enabled' => false]);
    Mail::fake();
});

it('requires email proof and opens the provider workspace without clinical approval or separate enrollment', function () {
    $provider = Provider::create(['name' => 'Email-only provider', 'email' => 'email-only@example.test', 'password_hash' => 'ProviderFixture9!']);
    $pending = $this->postJson('/api/v1/provider/auth/login', ['email' => $provider->email, 'password' => 'ProviderFixture9!'])
        ->assertOk()->assertJsonPath('data.challenge_required', true)->assertJsonMissingPath('data.token')->json('data');
    $code = Mail::queued(SignInChallengeMail::class)->last()->code;
    $this->postJson('/api/v1/provider/auth/challenge/verify', ['challenge_token' => $pending['challenge_token'], 'code' => $code === '000000' ? '000001' : '000000'])->assertUnprocessable();
    expect($provider->fresh()->is_verified)->toBeFalse();
    $session = $this->postJson('/api/v1/provider/auth/challenge/verify', ['challenge_token' => $pending['challenge_token'], 'code' => $code])
        ->assertOk()->assertJsonPath('data.provider.is_verified', true)->assertJsonPath('data.security_enrollment_required', false)->json('data');
    expect($provider->fresh()->license_number)->toBeNull();
    expect(DB::table('signin_security_preferences')->where('owner_type', Provider::class)->where('owner_id', $provider->id)->value('email_verified_at'))->not->toBeNull();
    app('auth')->forgetGuards();
    $this->withToken($session['token'])->getJson('/api/v1/provider/overview')->assertOk();
    $this->postJson('/api/v1/provider/auth/challenge/verify', ['challenge_token' => $pending['challenge_token'], 'code' => $code])->assertUnprocessable();
});

it('invalidates provider email verification when the address changes', function () {
    $provider = Provider::create(['name' => 'Provider fixture', 'email' => 'old-address@example.test', 'password_hash' => 'ProviderFixture9!', 'is_verified' => true]);
    $provider->update(['email' => 'new-address@example.test']);
    expect($provider->fresh()->is_verified)->toBeFalse();
});

it('keeps the patient sign-in rollout independent of provider email verification', function () {
    $user = User::factory()->create(['password' => 'PatientFixture9!']);
    $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'PatientFixture9!'])
        ->assertOk()->assertJsonMissingPath('data.challenge_token')->assertJsonStructure(['data' => ['token']]);
});
