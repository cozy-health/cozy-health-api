<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
});

it('throttles login after 10 attempts', function () {
    for ($i = 0; $i < 10; $i++) {
        $this->withHeader('Accept', 'application/json')
            ->postJson('/api/v1/auth/login', [
                'email' => "missing-$i@example.com",
                'password' => 'wrong-password',
            ])
            ->assertStatus(422);
    }

    $this->withHeader('Accept', 'application/json')
        ->postJson('/api/v1/auth/login', [
            'email' => 'missing@example.com',
            'password' => 'wrong-password',
        ])
        ->assertTooManyRequests();
});

it('includes retry after header on login throttle responses', function () {
    for ($i = 0; $i < 10; $i++) {
        $this->withHeader('Accept', 'application/json')
            ->postJson('/api/v1/auth/login', [
                'email' => "missing-$i@example.com",
                'password' => 'wrong-password',
            ]);
    }

    $response = $this->withHeader('Accept', 'application/json')
        ->postJson('/api/v1/auth/login', [
            'email' => 'missing@example.com',
            'password' => 'wrong-password',
        ]);

    $response->assertTooManyRequests()
        ->assertHeader('Retry-After');

    expect((int) $response->headers->get('Retry-After'))->toBeGreaterThan(0);
});

it('throttles register after 5 attempts', function () {
    for ($i = 0; $i < 5; $i++) {
        $this->withHeader('Accept', 'application/json')
            ->postJson('/api/v1/auth/register', [
                'name' => "Throttle User $i",
                'email' => "throttle-$i@example.com",
                'password' => 'Fixture7!Strong',
                'password_confirmation' => 'Fixture7!Strong',
            ])
            ->assertCreated();
    }

    $this->withHeader('Accept', 'application/json')
        ->postJson('/api/v1/auth/register', [
            'name' => 'Throttle User 6',
            'email' => 'throttle-6@example.com',
            'password' => 'Fixture7!Strong',
            'password_confirmation' => 'Fixture7!Strong',
        ])
        ->assertTooManyRequests();
});

it('throttles forgot password after 5 attempts per ip', function () {
    for ($i = 0; $i < 5; $i++) {
        $this->withHeader('Accept', 'application/json')
            ->postJson('/api/v1/auth/forgot-password', [
                'email' => "missing-$i@example.com",
            ])
            ->assertOk();
    }

    $this->withHeader('Accept', 'application/json')
        ->postJson('/api/v1/auth/forgot-password', [
            'email' => 'another-missing@example.com',
        ])
        ->assertTooManyRequests();
});

it('throttles reset password after 5 attempts', function () {
    for ($i = 0; $i < 5; $i++) {
        $this->withHeader('Accept', 'application/json')
            ->postJson('/api/v1/auth/reset-password', [
                'email' => 'missing@example.com',
                'token' => '123456',
                'password' => 'Fixture7!Strong',
            ])
            ->assertStatus(400);
    }

    $this->withHeader('Accept', 'application/json')
        ->postJson('/api/v1/auth/reset-password', [
            'email' => 'missing@example.com',
            'token' => '123456',
            'password' => 'Fixture7!Strong',
        ])
        ->assertTooManyRequests();
});
