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

it('keeps a created account recoverable when its welcome email cannot be queued', function (string $role) {
    Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('Synthetic welcome queue outage'));
    $email = 'welcome-queue-fixture@example.test';
    $prefix = $role === 'provider' ? '/api/v1/provider/auth' : '/api/v1/auth';
    $response = $this->postJson($prefix.'/register', ['name' => 'Welcome failure fixture', 'email' => $email,
        'password' => 'SecretChoice947!', 'password_confirmation' => 'SecretChoice947!'])->assertCreated();
    $this->assertDatabaseHas($role === 'provider' ? 'providers' : 'users', ['email' => $email]);
    expect($response->json('data.token'))->toBeString();
    expect(strtolower($response->json('message')))->not->toContain('email sent', 'email delivered');
})->with(['user', 'provider']);
