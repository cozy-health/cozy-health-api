<?php

use App\Mail\{PasswordResetRequestMail, ProviderPasswordResetMail};
use App\Models\{User, Provider};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Hash};

uses(RefreshDatabase::class);

it('only sends the current unexpired reset code for its own account role', function (string $role) {
    $this->freezeTime();
    $owner = $role === 'user' ? User::factory()->create() : Provider::create(['name' => 'Test Provider', 'email' => 'provider@example.test', 'is_active' => true]);
    $table = $role === 'user' ? 'password_reset_tokens' : 'provider_password_reset_tokens';
    $mail = $role === 'user' ? new PasswordResetRequestMail($owner, '543210') : new ProviderPasswordResetMail($owner, '543210');
    DB::table($table)->insert(['email' => $owner->email, 'token' => Hash::make('543210'), 'created_at' => now(), 'expires_at' => now()->addMinutes(15)]);
    expect($mail->shouldSend())->toBeTrue();
    DB::table($table)->where('email', $owner->email)->update(['token' => Hash::make('654321')]);
    expect($mail->shouldSend())->toBeFalse();
    DB::table($table)->where('email', $owner->email)->update(['token' => Hash::make('543210')]);
    $this->travel(15)->minutes();
    expect($mail->shouldSend())->toBeFalse();
    $this->travelBack();
    $owner->forceFill(['is_active' => false])->save();
    expect($mail->shouldSend())->toBeFalse();
    $owner->forceFill(['is_active' => true])->save();
    DB::table($table)->where('email', $owner->email)->delete();
    expect($mail->shouldSend())->toBeFalse();
})->with(['user', 'provider']);

it('does not send a queued reset to an address changed after requesting it', function (string $role) {
    $owner = $role === 'user' ? User::factory()->create() : Provider::create(['name' => 'Test Provider', 'email' => 'provider@example.test', 'is_active' => true]);
    $table = $role === 'user' ? 'password_reset_tokens' : 'provider_password_reset_tokens';
    $mail = $role === 'user' ? new PasswordResetRequestMail($owner, '543210') : new ProviderPasswordResetMail($owner, '543210');
    DB::table($table)->insert(['email' => $owner->email, 'token' => Hash::make('543210'), 'created_at' => now(), 'expires_at' => now()->addMinutes(15)]);
    $owner->forceFill(['email' => 'changed@example.test'])->save();
    expect($mail->shouldSend())->toBeFalse();
})->with(['user', 'provider']);
