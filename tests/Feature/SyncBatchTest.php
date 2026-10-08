<?php

use App\Models\MoodEntry;
use App\Models\SubscriptionStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->syncUser = User::factory()->create();
    Sanctum::actingAs($this->syncUser);
});

function syncMoodOperation(string $clientId, array $data = []): array
{
    return [
        'client_operation_id' => $clientId,
        'type' => 'mood_entry',
        'action' => 'upsert',
        'data' => ['id' => (string) Str::uuid(), 'mood' => 'good', 'intensity' => 5, ...$data],
    ];
}

it('returns a results envelope with identifiers for all three operations', function () {
    $ops = [syncMoodOperation('op-1'), syncMoodOperation('op-2'), syncMoodOperation('op-3')];
    $response = $this->postJson('/api/v1/sync/batch', ['operations' => $ops])
        ->assertOk()->assertJsonCount(3, 'data.results');

    foreach ($ops as $index => $op) {
        $response->assertJsonPath("data.results.$index.client_operation_id", $op['client_operation_id'])
            ->assertJsonPath("data.results.$index.client_id", $op['client_operation_id'])
            ->assertJsonPath("data.results.$index.id", $op['data']['id'])
            ->assertJsonPath("data.results.$index.type", 'mood_entry')
            ->assertJsonPath("data.results.$index.success", true);
        $this->assertDatabaseHas('mood_entries', ['id' => $op['data']['id'], 'user_id' => $this->syncUser->id]);
    }
});

it('returns matched failures alongside successful operations', function () {
    $valid = syncMoodOperation('valid');
    $invalid = ['client_operation_id' => 'invalid', 'type' => 'mood_entry', 'action' => 'upsert'];
    $this->postJson('/api/v1/sync/batch', ['operations' => [$valid, $invalid]])
        ->assertOk()->assertJsonCount(2, 'data.results')
        ->assertJsonPath('data.results.0.success', true)
        ->assertJsonPath('data.results.1.client_operation_id', 'invalid')
        ->assertJsonPath('data.results.1.success', false)
        ->assertJsonPath('data.results.1.error', 'Invalid operation format');
    $this->assertDatabaseHas('mood_entries', ['id' => $valid['data']['id']]);
});

it('accepts both top level and nested ids for deletion', function () {
    foreach (['top', 'nested'] as $shape) {
        $entry = MoodEntry::create(['id' => (string) Str::uuid(), 'user_id' => $this->syncUser->id, 'mood' => 'good', 'intensity' => 5]);
        $op = ['client_operation_id' => $shape, 'type' => 'mood_entry', 'action' => 'delete'];
        $op += $shape === 'top' ? ['id' => $entry->id] : ['data' => ['id' => $entry->id]];
        $this->postJson('/api/v1/sync/batch', ['operations' => [$op]])
            ->assertOk()->assertJsonPath('data.results.0.success', true)
            ->assertJsonPath('data.results.0.id', $entry->id);
        $this->assertSoftDeleted('mood_entries', ['id' => $entry->id]);
    }
});

it('routes mobile subscription upserts to subscription statuses', function () {
    $id = 'sub_123456789';
    $this->postJson('/api/v1/sync/batch', ['operations' => [[
        'client_operation_id' => 'subscription:'.$id,
        'type' => 'subscription', 'action' => 'upsert',
        'data' => ['id' => $id, 'plan' => 'pro', 'status' => 'active', 'expires_at' => now()->addMonth()->toIso8601String()],
    ]]])->assertOk()->assertJsonPath('data.results.0.success', true);
    $this->assertDatabaseHas('subscription_statuses', ['id' => $id, 'user_id' => $this->syncUser->id, 'plan' => 'pro', 'status' => 'active']);
});

it('cancels an existing subscription without replacing its plan or expiry', function () {
    $subscription = SubscriptionStatus::create(['id' => (string) Str::uuid(), 'user_id' => $this->syncUser->id,
        'plan' => 'pro', 'status' => 'active', 'expires_at' => now()->addMonth()]);
    $expiry = $subscription->expires_at->toIso8601String();
    $this->postJson('/api/v1/sync/batch', ['operations' => [[
        'client_operation_id' => 'cancel', 'type' => 'subscription', 'action' => 'cancel',
        'data' => ['id' => $subscription->id, 'plan' => 'pro', 'status' => 'inactive'],
    ]]])->assertOk()->assertJsonPath('data.results.0.success', true);
    $subscription->refresh();
    expect($subscription->status)->toBe('inactive');
    expect($subscription->plan)->toBe('pro');
    expect($subscription->expires_at->toIso8601String())->toBe($expiry);
    expect($subscription->cancelled_at)->not->toBeNull();
});

it('keeps database failures isolated and reports them instead of acknowledging success', function () {
    $first = syncMoodOperation('first');
    $broken = syncMoodOperation('broken');
    unset($broken['data']['mood']); // Required DB column: force a real SQL failure.
    $last = syncMoodOperation('last');
    $response = $this->postJson('/api/v1/sync/batch', ['operations' => [$first, $broken, $last]])
        ->assertOk()->assertJsonCount(3, 'data.results')
        ->assertJsonPath('data.results.0.success', true)
        ->assertJsonPath('data.results.1.client_operation_id', 'broken')
        ->assertJsonPath('data.results.1.success', false)
        ->assertJsonPath('data.results.1.error', 'Unable to apply sync operation')
        ->assertJsonPath('data.results.2.success', true);
    $this->assertDatabaseHas('mood_entries', ['id' => $first['data']['id']]);
    $this->assertDatabaseMissing('mood_entries', ['id' => $broken['data']['id']]);
    $this->assertDatabaseHas('mood_entries', ['id' => $last['data']['id']]);

    // Mirror mobile's identifier and success lookup using the actual wire response.
    $byId = collect($response->json('data.results'))->keyBy('client_operation_id');
    expect($byId['broken']['success'])->toBeFalse();
});

it('reports unknown types actions and malformed entries individually', function () {
    $unknownType = syncMoodOperation('unknown-type');
    $unknownType['type'] = 'not_a_model';
    $unknownAction = syncMoodOperation('unknown-action');
    $unknownAction['action'] = 'cancel';
    $this->postJson('/api/v1/sync/batch', ['operations' => [$unknownType, $unknownAction, 'malformed']])
        ->assertOk()->assertJsonCount(3, 'data.results')
        ->assertJsonPath('data.results.0.error', 'Unknown type')
        ->assertJsonPath('data.results.1.error', 'Unknown action')
        ->assertJsonPath('data.results.2.error', 'Invalid operation format');
});

it('uses the record id for legacy operations without a client operation id', function () {
    $op = syncMoodOperation('legacy');
    unset($op['client_operation_id']);
    $this->postJson('/api/v1/sync/batch', ['operations' => [$op]])
        ->assertOk()->assertJsonPath('data.results.0.client_operation_id', $op['data']['id'])
        ->assertJsonPath('data.results.0.success', true);
});

it('does not delete or overwrite another users record', function () {
    $other = User::factory()->create();
    $id = (string) Str::uuid();
    MoodEntry::withoutGlobalScopes()->create(['id' => $id, 'user_id' => $other->id, 'mood' => 'good', 'intensity' => 5]);
    $op = syncMoodOperation('overwrite', ['id' => $id, 'user_id' => $other->id, 'mood' => 'bad']);
    $this->postJson('/api/v1/sync/batch', ['operations' => [$op]])
        ->assertOk()->assertJsonPath('data.results.0.success', false);
    $this->postJson('/api/v1/sync/batch', ['operations' => [[
        'client_operation_id' => 'delete-other', 'type' => 'mood_entry', 'action' => 'delete', 'id' => $id,
    ]]])->assertOk();
    $this->assertDatabaseHas('mood_entries', ['id' => $id, 'user_id' => $other->id, 'mood' => 'good']);
});

it('reports cancellation of a missing subscription as a failure', function () {
    $this->postJson('/api/v1/sync/batch', ['operations' => [[
        'client_operation_id' => 'missing', 'type' => 'subscription', 'action' => 'cancel', 'id' => 'missing',
    ]]])->assertOk()->assertJsonPath('data.results.0.success', false)
        ->assertJsonPath('data.results.0.error', 'Subscription not found');
});
