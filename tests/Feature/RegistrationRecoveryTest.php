<?php

use App\Models\User;
use App\Services\AuthSessions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

it('rolls back registration when a session or challenge cannot be created', function (string $role) {
    Mail::fake();
    $email = 'registration-recovery@example.test';
    $this->mock(AuthSessions::class, function ($mock) {
        $mock->shouldReceive('issue')->once()->andThrow(new RuntimeException('Synthetic session issuance failure'));
    });
    $prefix = $role === 'provider' ? '/api/v1/provider/auth' : '/api/v1/auth';
    $this->postJson($prefix.'/register', ['name' => 'Development registration test', 'email' => $email,
        'password' => 'SecretChoice947!', 'password_confirmation' => 'SecretChoice947!'])
        ->assertServerError();
    $this->assertDatabaseMissing($role === 'provider' ? 'providers' : 'users', ['email' => $email]);
    if ($role === 'user') {
        $table = (new User)->profile()->getRelated()->getTable();
        expect(DB::table($table)->where('email', $email)->exists())->toBeFalse();
    }
    Mail::assertNothingSent();
    Mail::assertNothingQueued();
})->with(['user', 'provider']);
