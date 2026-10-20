<?php

use App\Models\SubscriptionStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('returns no subscription state when the authenticated user has none', function () {
    Sanctum::actingAs(User::factory()->create());

    $this->getJson('/api/v1/subscription')
        ->assertOk()
        ->assertJsonPath('data.plan', null)
        ->assertJsonPath('data.status', 'none');
});

it('returns the authenticated user subscription status', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $subscription = SubscriptionStatus::create([
        'id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'plan' => 'free',
        'status' => 'active',
        'platform' => 'manual',
        'started_at' => now(),
        'expires_at' => now()->addMonth(),
        'last_checked' => now(),
    ]);

    $this->getJson('/api/v1/subscription')
        ->assertOk()
        ->assertJsonPath('data.id', $subscription->id)
        ->assertJsonPath('data.user_id', $user->id);
});
