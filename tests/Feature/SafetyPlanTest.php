<?php

use App\Models\SafetyPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('returns 404 when the authenticated user has no safety plan', function () {
    Sanctum::actingAs(User::factory()->create());

    $this->getJson('/api/v1/safety-plan')
        ->assertNotFound()
        ->assertJsonPath('error.code', 'NOT_FOUND');
});

it('returns the authenticated user safety plan', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $plan = SafetyPlan::create([
        'id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'warning_signs' => ['Racing thoughts'],
        'coping_strategies' => ['Box breathing'],
        'distractions' => [],
        'people' => [],
        'professionals' => [],
        'environment_steps' => [],
        'is_complete' => true,
        'is_deleted' => false,
    ]);

    $this->getJson('/api/v1/safety-plan')
        ->assertOk()
        ->assertJsonPath('data.id', $plan->id)
        ->assertJsonPath('data.user_id', $user->id);
});
