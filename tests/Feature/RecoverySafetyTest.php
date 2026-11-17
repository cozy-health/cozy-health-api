<?php

use App\Models\{User, Provider};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Hash, Mail};
use Laravel\Sanctum\Sanctum;
use App\Mail\VerifyEmailMail;

uses(RefreshDatabase::class);

it('requires fresh ownership verification after changing an email address', function () {
    Mail::fake(); $owner = User::factory()->create(); Sanctum::actingAs($owner);
    $this->patchJson('/api/v1/me', ['email' => 'changed-owner@example.test'])->assertOk();
    expect($owner->fresh()->email_verified_at)->toBeNull();
});

it('does not accept an old email code to verify a different address', function () {
    Mail::fake(); $owner = User::factory()->create(['email_verified_at' => null]); Sanctum::actingAs($owner);
    $this->postJson('/api/v1/auth/email-verification/resend')->assertOk();
    $code = null;
    Mail::assertQueued(VerifyEmailMail::class, function ($mail) use (&$code) { $code = $mail->code; return true; });
    $this->patchJson('/api/v1/me', ['email' => 'different-owner@example.test'])->assertOk();
    $this->postJson('/api/v1/auth/email-verification/verify', ['code' => $code])->assertUnprocessable();
    expect($owner->fresh()->email_verified_at)->toBeNull();
});

function recoveryOwner(string $role): User|Provider
{
    return $role === 'user' ? User::factory()->create(['password' => 'OldSecret947!'])
        : Provider::create(['name' => 'Recovery Provider', 'email' => 'recovery@example.test', 'password_hash' => 'OldSecret947!']);
}

it('exhausts a reset code after five incorrect guesses across different ips', function (string $role) {
    Mail::fake(); $owner = recoveryOwner($role);
    $table = $role === 'user' ? 'password_reset_tokens' : 'provider_password_reset_tokens';
    $path = $role === 'user' ? '/api/v1/auth/reset-password' : '/api/v1/provider/auth/reset-password';
    DB::table($table)->insert(['email' => $owner->email, 'token' => Hash::make('543210'), 'created_at' => now(), 'expires_at' => now()->addMinutes(15)]);
    $data = ['email' => $owner->email, 'password' => 'UpdatedSecret836!', 'password_confirmation' => 'UpdatedSecret836!'];
    $owner->createToken('still-valid-after-wrong-guesses');
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.($attempt + 1)])
            ->postJson($path, [...$data, 'token' => '111111'])->assertStatus(400);
    }
    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.20'])->postJson($path, [...$data, 'token' => '543210'])->assertStatus(400);
    expect(Hash::check('OldSecret947!', $role === 'user' ? $owner->fresh()->password : $owner->fresh()->password_hash))->toBeTrue();
    expect($owner->tokens()->count())->toBe(1);
    Mail::assertNothingOutgoing();
})->with(['user', 'provider']);

it('does not undo or misreport a completed reset when confirmation queueing fails', function (string $role) {
    $owner = recoveryOwner($role);
    $table = $role === 'user' ? 'password_reset_tokens' : 'provider_password_reset_tokens';
    $path = $role === 'user' ? '/api/v1/auth/reset-password' : '/api/v1/provider/auth/reset-password';
    DB::table($table)->insert(['email' => $owner->email, 'token' => Hash::make('543210'), 'created_at' => now(), 'expires_at' => now()->addMinutes(15)]);
    $owner->createToken('before-reset');
    Mail::shouldReceive('to')->once()->with($owner->email)->andThrow(new RuntimeException('Synthetic queue failure'));
    $this->postJson($path, ['email' => $owner->email, 'token' => '543210', 'password' => 'UpdatedSecret836!', 'password_confirmation' => 'UpdatedSecret836!'])
        ->assertOk()->assertJsonPath('message', 'Your password has been successfully reset. The confirmation email could not be queued.');
    expect(Hash::check('UpdatedSecret836!', $role === 'user' ? $owner->fresh()->password : $owner->fresh()->password_hash))->toBeTrue();
    $this->assertDatabaseMissing($table, ['email' => $owner->email]);
    expect($owner->tokens()->count())->toBe(0);
})->with(['user', 'provider']);

it('keeps patient and provider recovery separate when they share an email', function () {
    Mail::fake();
    $patient = User::factory()->create(['email' => 'shared@example.test', 'password' => 'PatientSecret947!']);
    $provider = Provider::create(['name' => 'Separate Provider', 'email' => $patient->email, 'password_hash' => 'ProviderSecret947!']);
    $provider->createToken('provider-remains-active');
    foreach (['password_reset_tokens', 'provider_password_reset_tokens'] as $table) {
        DB::table($table)->insert(['email' => $patient->email, 'token' => Hash::make('543210'), 'created_at' => now(), 'expires_at' => now()->addMinutes(15)]);
    }
    $this->postJson('/api/v1/auth/reset-password', ['email' => $patient->email, 'token' => '543210', 'password' => 'UpdatedSecret836!', 'password_confirmation' => 'UpdatedSecret836!'])->assertOk();
    expect(Hash::check('ProviderSecret947!', $provider->fresh()->password_hash))->toBeTrue();
    expect($provider->tokens()->count())->toBe(1);
    $this->assertDatabaseHas('provider_password_reset_tokens', ['email' => $provider->email, 'failed_attempts' => 0]);
});

it('exhausts verification guesses without rolling the counter back on rejection', function () {
    Mail::fake(); $owner = User::factory()->create(['email_verified_at' => null]);
    $this->freezeTime();
    DB::table('email_verification_codes')->insert(['user_id' => $owner->id, 'code_hash' => Hash::make('543210'), 'expires_at' => now()->addMinutes(15), 'email_key' => hash_hmac('sha256', $owner->email, config('app.key'))]);
    Sanctum::actingAs($owner);
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.($attempt + 1)])
            ->postJson('/api/v1/auth/email-verification/verify', ['code' => '111111'])->assertUnprocessable();
        $this->travel(61)->seconds();
    }
    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.20'])
        ->postJson('/api/v1/auth/email-verification/verify', ['code' => '543210'])->assertUnprocessable();
    expect($owner->fresh()->email_verified_at)->toBeNull();
});

it('suppresses delayed verification emails after replacement expiry use or address change', function () {
    $this->freezeTime();
    $owner = User::factory()->create(['email_verified_at' => null]);
    $mail = new VerifyEmailMail('543210', $owner->id, $owner->email);
    DB::table('email_verification_codes')->insert(['user_id' => $owner->id, 'code_hash' => Hash::make('543210'), 'expires_at' => now()->addMinutes(15), 'email_key' => hash_hmac('sha256', $owner->email, config('app.key'))]);
    expect($mail->shouldSend())->toBeTrue();
    DB::table('email_verification_codes')->where('user_id', $owner->id)->update(['code_hash' => Hash::make('654321')]);
    expect($mail->shouldSend())->toBeFalse();
    DB::table('email_verification_codes')->where('user_id', $owner->id)->update(['code_hash' => Hash::make('543210')]);
    $this->travel(15)->minutes();
    expect($mail->shouldSend())->toBeFalse();
    $this->travelBack();
    $owner->forceFill(['email' => 'updated@example.test'])->save();
    expect($mail->shouldSend())->toBeFalse();
    $owner->forceFill(['email' => $mail->recipientEmail, 'email_verified_at' => now()])->save();
    expect($mail->shouldSend())->toBeFalse();
});

it('allows recovery with a newly requested code after exhausting the previous one', function (string $role) {
    Mail::fake(); $owner = recoveryOwner($role);
    $table = $role === 'user' ? 'password_reset_tokens' : 'provider_password_reset_tokens';
    $prefix = $role === 'user' ? '/api/v1/auth/' : '/api/v1/provider/auth/';
    DB::table($table)->insert(['email' => $owner->email, 'token' => Hash::make('543210'), 'created_at' => now(), 'expires_at' => now()->addMinutes(15), 'failed_attempts' => 5]);
    $this->postJson($prefix.'forgot-password', ['email' => $owner->email])->assertOk();
    $class = $role === 'user' ? \App\Mail\PasswordResetRequestMail::class : \App\Mail\ProviderPasswordResetMail::class;
    $code = null;
    Mail::assertQueued($class, function ($mail) use (&$code) { $code = $mail->token; return true; });
    $this->postJson($prefix.'reset-password', ['email' => $owner->email, 'token' => $code, 'password' => 'UpdatedSecret836!', 'password_confirmation' => 'UpdatedSecret836!'])->assertOk();
    expect(Hash::check('UpdatedSecret836!', $role === 'user' ? $owner->fresh()->password : $owner->fresh()->password_hash))->toBeTrue();
})->with(['user', 'provider']);
