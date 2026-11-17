<?php

use App\Models\MoodEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    Sanctum::actingAs(User::factory()->create());
});

function integrityMoodOperation(array $changes = []): array
{
    return ['client_operation_id' => (string) Str::uuid(), 'type' => 'mood_entry', 'action' => 'upsert',
        'data' => ['id' => (string) Str::uuid(), 'mood' => 'good', 'intensity' => 5, ...$changes]];
}

it('rejects batches exceeding one hundred operations before writing anything', function () {
    $operations = array_map(fn () => integrityMoodOperation(), range(1, 101));
    $this->postJson('/api/v1/sync/batch', ['operations' => $operations])
        ->assertUnprocessable()->assertJsonValidationErrors('operations');
    expect(MoodEntry::count())->toBe(0);
});

it('accepts exactly one hundred operations', function () {
    $operations = array_map(fn () => integrityMoodOperation(), range(1, 100));
    $response = $this->postJson('/api/v1/sync/batch', ['operations' => $operations])
        ->assertOk()->assertJsonCount(100, 'data.results');
    expect(collect($response->json('data.results'))->every(fn ($result) => $result['success']))->toBeTrue();
    expect(MoodEntry::count())->toBe(100);
});

it('rejects invalid mood fields per operation while retaining valid writes', function (array $changes, string $field) {
    $invalid = integrityMoodOperation($changes);
    $valid = integrityMoodOperation();
    $this->postJson('/api/v1/sync/batch', ['operations' => [$invalid, $valid]])
        ->assertOk()->assertJsonPath('data.results.0.client_operation_id', $invalid['client_operation_id'])
        ->assertJsonPath('data.results.0.success', false)
        ->assertJsonPath('data.results.0.error_code', 'invalid_payload')
        ->assertJsonStructure(['data' => ['results' => [0 => ['errors' => [$field]]]]])
        ->assertJsonPath('data.results.1.success', true);
    $this->assertDatabaseMissing('mood_entries', ['id' => $invalid['data']['id']]);
    $this->assertDatabaseHas('mood_entries', ['id' => $valid['data']['id']]);
})->with([
    'intensity above bound' => [['intensity' => 11], 'intensity'],
    'intensity below bound' => [['intensity' => -1], 'intensity'],
    'fractional intensity' => [['intensity' => 4.5], 'intensity'],
    'missing mood' => [['mood' => null], 'mood'],
    'long mood' => [['mood' => str_repeat('m', 21)], 'mood'],
    'sleep above bound' => [['sleep_quality' => 11], 'sleep_quality'],
    'invalid energy' => [['energy_level' => -1], 'energy_level'],
    'invalid triggers' => [['triggers' => 'work'], 'triggers'],
    'invalid body sensations' => [['body_sensations' => ['bad' => []]], 'body_sensations.bad'],
    'invalid crisis flag' => [['is_crisis_flagged' => 'invalid'], 'is_crisis_flagged'],
]);

it('validates partial mood updates against existing values without erasing omitted fields', function () {
    $operation = integrityMoodOperation(['note' => 'Keep this note']);
    $this->postJson('/api/v1/sync/batch', ['operations' => [$operation]])->assertOk();
    $operation['data'] = ['id' => $operation['data']['id'], 'intensity' => 8];
    $this->postJson('/api/v1/sync/batch', ['operations' => [$operation]])
        ->assertOk()->assertJsonPath('data.results.0.success', true);
    $this->assertDatabaseHas('mood_entries', ['id' => $operation['data']['id'], 'mood' => 'good', 'intensity' => 8, 'note' => 'Keep this note']);
});

it('preserves an existing mood crisis flag when a partial sync edit omits it', function () {
    $operation = integrityMoodOperation(['is_crisis_flagged' => true]);
    $this->postJson('/api/v1/sync/batch', ['operations' => [$operation]])->assertOk();
    $operation['data'] = ['id' => $operation['data']['id'], 'note' => 'Edited note'];
    $this->postJson('/api/v1/sync/batch', ['operations' => [$operation]])
        ->assertOk()->assertJsonPath('data.results.0.success', true);
    expect(MoodEntry::find($operation['data']['id'])->is_crisis_flagged)->toBeTrue();
});
