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
                'email' => 'missing@example.com',
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
                'email' => 'missing@example.com',
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
                'password' => 'password123',
                'password_confirmation' => 'password123',
            ])
            ->assertCreated();
    }

    $this->withHeader('Accept', 'application/json')
        ->postJson('/api/v1/auth/register', [
            'name' => 'Throttle User 6',
            'email' => 'throttle-6@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])
        ->assertTooManyRequests();
});

it('throttles forgot password after 3 attempts', function () {
    for ($i = 0; $i < 3; $i++) {
        $this->withHeader('Accept', 'application/json')
            ->postJson('/api/v1/auth/forgot-password', [
                'email' => 'missing@example.com',
            ])
            ->assertOk();
    }

    $this->withHeader('Accept', 'application/json')
        ->postJson('/api/v1/auth/forgot-password', [
            'email' => 'missing@example.com',
        ])
        ->assertTooManyRequests();
});

it('throttles reset password after 5 attempts', function () {
    for ($i = 0; $i < 5; $i++) {
        $this->withHeader('Accept', 'application/json')
            ->postJson('/api/v1/auth/reset-password', [
                'email' => 'missing@example.com',
                'token' => '123456',
                'password' => 'password123',
            ])
            ->assertStatus(400);
    }

    $this->withHeader('Accept', 'application/json')
        ->postJson('/api/v1/auth/reset-password', [
            'email' => 'missing@example.com',
            'token' => '123456',
            'password' => 'password123',
        ])
        ->assertTooManyRequests();
});
