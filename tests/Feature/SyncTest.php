<?php

use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function preferencesOperation(array $changes = []): array
{
    return ['type' => 'user_preferences', 'action' => 'upsert', 'data' => [
        'id' => (string) Str::uuid(), 'focus_areas' => ['anxiety', 'sleep'],
        'current_challenges' => ['work', 'family'], 'check_in_frequency' => 'daily',
        'attribution' => 'friend', 'completed_at' => now()->toIso8601String(),
        'skipped' => false, ...$changes,
    ]];
}

beforeEach(function () {
    $this->preferencesUser = User::factory()->create();
    Sanctum::actingAs($this->preferencesUser);
});

test('test_sync_accepts_user_preferences_type', function () {
    $this->postJson('/api/v1/sync/batch', ['operations' => [preferencesOperation()]])
        ->assertOk()->assertJsonPath('data.results.0.success', true);
    expect($this->preferencesUser->profile->preferences['focus_areas'])->toBe(['anxiety', 'sleep']);
});

test('test_sync_updates_existing_preferences', function () {
    $profile = UserProfile::create(['id' => (string) Str::uuid(), 'user_id' => $this->preferencesUser->id,
        'name' => 'Existing name', 'email' => $this->preferencesUser->email, 'theme' => 'dark']);
    foreach (['daily', 'when_needed'] as $frequency) {
        $this->postJson('/api/v1/sync/batch', ['operations' => [preferencesOperation(['check_in_frequency' => $frequency])]])
            ->assertOk()->assertJsonPath('data.results.0.success', true);
    }
    expect(UserProfile::count())->toBe(1);
    expect($profile->refresh()->preferences['check_in_frequency'])->toBe('when_needed');
    expect($profile->name)->toBe('Existing name');
    expect($profile->theme)->toBe('dark');
});

test('test_sync_creates_preferences_for_new_user', function () {
    expect(UserProfile::count())->toBe(0);
    $this->postJson('/api/v1/sync/batch', ['operations' => [preferencesOperation(['focus_areas' => [], 'current_challenges' => [], 'skipped' => true, 'attribution' => null])]])
        ->assertOk()->assertJsonPath('data.results.0.success', true);
    $profile = $this->preferencesUser->profile;
    expect($profile->name)->toBe($this->preferencesUser->name);
    expect($profile->email)->toBe($this->preferencesUser->email);
    expect($profile->preferences['skipped'])->toBeTrue();
    expect($profile->onboarding_completed_at)->not->toBeNull();
});

test('test_sync_preferences_validates_payload', function () {
    $bad = [
        ['focus_areas' => ['unknown']], ['focus_areas' => 'anxiety'],
        ['focus_areas' => ['sleep', 'sleep']], ['current_challenges' => ['unknown']],
        ['check_in_frequency' => 'hourly'], ['attribution' => 'unknown'],
        ['completed_at' => 'not-a-date'], ['skipped' => 'perhaps'],
    ];
    foreach ($bad as $changes) {
        $this->postJson('/api/v1/sync/batch', ['operations' => [preferencesOperation($changes), preferencesOperation()]])
            ->assertOk()->assertJsonPath('data.results.0.success', false)
            ->assertJsonPath('data.results.0.error', 'Invalid preferences payload')
            ->assertJsonPath('data.results.1.success', true);
    }
    expect(UserProfile::count())->toBe(1);
});

test('sync normalizes the actual Phase 6 mobile labels', function () {
    $this->postJson('/api/v1/sync/batch', ['operations' => [preferencesOperation([
        'focus_areas' => ['Anxiety', 'Low mood', 'Self-growth'], 'current_challenges' => ['Work', 'Family'],
        'check_in_frequency' => 'A few times a week', 'attribution' => 'App Store',
    ])]])->assertOk()->assertJsonPath('data.results.0.success', true);
    expect($this->preferencesUser->profile->preferences)->toMatchArray([
        'focus_areas' => ['anxiety', 'low mood', 'self-growth'], 'current_challenges' => ['work', 'family'],
        'check_in_frequency' => 'few_times', 'attribution' => 'app_store',
    ]);
});

test('sync preferences ignores supplied ownership and preserves other users', function () {
    $other = User::factory()->create();
    $profile = UserProfile::withoutGlobalScopes()->create(['id' => (string) Str::uuid(), 'user_id' => $other->id,
        'name' => $other->name, 'email' => $other->email, 'preferences' => ['sentinel' => true]]);
    $this->postJson('/api/v1/sync/batch', ['operations' => [preferencesOperation(['id' => $profile->id, 'user_id' => $other->id])]])
        ->assertOk()->assertJsonPath('data.results.0.success', true);
    expect($profile->refresh()->preferences)->toBe(['sentinel' => true]);
    expect(UserProfile::withoutGlobalScopes()->count())->toBe(2);
});

test('sync preferences delete clears only the current users answers', function () {
    $this->postJson('/api/v1/sync/batch', ['operations' => [preferencesOperation()]])->assertOk();
    $operation = preferencesOperation();
    $operation['action'] = 'delete';
    $this->postJson('/api/v1/sync/batch', ['operations' => [$operation]])
        ->assertOk()->assertJsonPath('data.results.0.success', true);
    expect($this->preferencesUser->profile->preferences)->toBeNull();
    expect(UserProfile::count())->toBe(1);
});
