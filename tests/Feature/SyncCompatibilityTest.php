<?php

use App\Models\ChatConversation;
use App\Models\MoodEntry;
use App\Models\SafetyPlan;
use App\Models\SavedArticle;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->syncUser = User::factory()->create();
    Sanctum::actingAs($this->syncUser);
    $this->operations = json_decode(file_get_contents(base_path('tests/Fixtures/mobile_sync_operations.json')), true, flags: JSON_THROW_ON_ERROR);
    $this->operation = fn (string $type) => collect($this->operations)->firstWhere('type', $type);
});

it('persists compatible payloads and rejects an unknown unversioned quiz without trusting its score', function () {
    $profile = UserProfile::create(['id' => (string) Str::uuid(), 'user_id' => $this->syncUser->id,
        'name' => 'Before', 'email' => $this->syncUser->email]);
    $response = $this->postJson('/api/v1/sync/batch', ['operations' => $this->operations])->assertOk()->assertJsonCount(11, 'data.results');
    foreach ($this->operations as $index => $operation) {
        if (in_array($operation['type'], ['subscription', 'subscription_status'], true)) { $response->assertJsonPath("data.results.$index.success", false)->assertJsonPath("data.results.$index.error_code", 'access_denied'); continue; }
        if ($operation['type'] === 'quiz_attempt') {
            $response->assertJsonPath("data.results.$index.success", false)->assertJsonPath("data.results.$index.error_code", 'invalid_payload');
            continue;
        }
        $response->assertJsonPath("data.results.$index.success", true)
            ->assertJsonPath("data.results.$index.client_operation_id", $operation['client_operation_id']);
    }
    foreach (['mood_entries', 'journal_entries', 'chat_conversations', 'chat_messages', 'safety_plans',
        'user_profiles', 'saved_articles', 'app_notifications'] as $table) {
        $this->assertDatabaseCount($table, 1);
    }
    $this->assertDatabaseHas('user_profiles', ['id' => $profile->id, 'name' => 'Synthetic user']);
    expect($profile->fresh()->preferences['focus_areas'])->toBe(['sleep']);
    $bookmark = SavedArticle::first();
    expect(strlen($bookmark->id))->toBe(36);
    expect($bookmark->article_slug)->toBe($bookmark->article_id);
    expect($bookmark->read_time_minutes)->toBe(0);
    expect(ChatConversation::first()->title)->toBe('Conversation');
});

it('retries all mobile payloads without duplicating singleton or bookmarked records', function () {
    for ($retry = 0; $retry < 2; $retry++) {
        $response = $this->postJson('/api/v1/sync/batch', ['operations' => $this->operations])->assertOk();
        foreach ($this->operations as $index => $operation) {
            if (in_array($operation['type'], ['subscription', 'subscription_status'], true)) { $response->assertJsonPath("data.results.$index.success", false)->assertJsonPath("data.results.$index.error_code", 'access_denied'); continue; }
        if ($operation['type'] === 'quiz_attempt') { $response->assertJsonPath("data.results.$index.success", false); continue; }
            $response->assertJsonPath("data.results.$index.success", true);
        }
    }
    foreach (['mood_entries', 'journal_entries', 'chat_conversations', 'chat_messages', 'safety_plans',
        'user_profiles', 'saved_articles', 'app_notifications'] as $table) {
        $this->assertDatabaseCount($table, 1);
    }
});

it('updates an existing safety plan using a different local id without a unique user collision', function () {
    $plan = SafetyPlan::create(['id' => (string) Str::uuid(), 'user_id' => $this->syncUser->id,
        'warning_signs' => ['Before'], 'last_updated_at' => '2026-10-09T12:00:00Z']);
    $operation = ($this->operation)('safety_plan');
    $this->postJson('/api/v1/sync/batch', ['operations' => [$operation]])->assertOk()->assertJsonPath('data.results.0.success', true);
    $this->assertDatabaseCount('safety_plans', 1);
    expect($plan->fresh()->warning_signs)->toBe(['Synthetic warning']);
    expect($plan->fresh()->client_updated_at->toIso8601String())->toBe('2026-10-10T12:00:00+00:00');
});

it('acknowledges an old mood retry without overwriting the newer value', function () {
    $operation = ($this->operation)('mood_entry');
    $newer = $operation;
    $newer['data']['note'] = 'Newer';
    $newer['data']['client_updated_at'] = '2026-10-10T12:01:00Z';
    $this->postJson('/api/v1/sync/batch', ['operations' => [$newer]])->assertOk()->assertJsonPath('data.results.0.success', true);
    $this->postJson('/api/v1/sync/batch', ['operations' => [$operation]])->assertOk()->assertJsonPath('data.results.0.success', true);
    expect(MoodEntry::first()->note)->toBe('Newer');
});

it('deletes bookmarks using the mobile article id and restores them on an explicit resave', function () {
    $operation = ($this->operation)('saved_article');
    $this->postJson('/api/v1/sync/batch', ['operations' => [$operation]])->assertOk()->assertJsonPath('data.results.0.success', true);
    $record = SavedArticle::first();
    $this->postJson('/api/v1/sync/batch', ['operations' => [[
        'type' => 'saved_article', 'action' => 'delete', 'id' => $operation['data']['article_id'],
    ]]])->assertOk()->assertJsonPath('data.results.0.success', true);
    $this->assertSoftDeleted('saved_articles', ['id' => $record->id]);
    $this->postJson('/api/v1/sync/batch', ['operations' => [$operation]])->assertOk()->assertJsonPath('data.results.0.success', true);
    expect(SavedArticle::first()->id)->toBe($record->id);
    $this->assertDatabaseCount('saved_articles', 1);
});

it('preserves reviewed bookmark metadata when retrying a legacy payload without those fields', function () {
    $operation = ($this->operation)('saved_article');
    $this->postJson('/api/v1/sync/batch', ['operations' => [$operation]])->assertOk();
    SavedArticle::first()->update(['article_slug' => 'known-slug', 'category' => 'Known', 'read_time_minutes' => 5]);
    $this->postJson('/api/v1/sync/batch', ['operations' => [$operation]])->assertOk()->assertJsonPath('data.results.0.success', true);
    expect(SavedArticle::first()->article_slug)->toBe('known-slug');
    expect(SavedArticle::first()->read_time_minutes)->toBe(5);
});

it('keeps deletions as tombstones when old updates arrive later', function () {
    $operation = ($this->operation)('mood_entry');
    $this->postJson('/api/v1/sync/batch', ['operations' => [$operation]])->assertOk();
    $this->travelTo(now()->setDate(2026, 10, 11));
    $this->postJson('/api/v1/sync/batch', ['operations' => [[
        'type' => 'mood_entry', 'action' => 'delete', 'id' => $operation['data']['id'],
    ]]])->assertOk()->assertJsonPath('data.results.0.success', true);
    $this->postJson('/api/v1/sync/batch', ['operations' => [$operation]])->assertOk()->assertJsonPath('data.results.0.success', true);
    $this->assertSoftDeleted('mood_entries', ['id' => $operation['data']['id']]);
});

it('returns a safe error category without exposing failed SQL payloads', function () {
    $operation = ($this->operation)('mood_entry');
    unset($operation['data']['mood']);
    $response = $this->postJson('/api/v1/sync/batch', ['operations' => [$operation]])->assertOk()
        ->assertJsonPath('data.results.0.success', false)->assertJsonPath('data.results.0.error_code', 'database');
    expect($response->getContent())->not->toContain('SQLSTATE')->not->toContain('Synthetic mood');
});

it('rejects both aliases without changing server subscription state', function () {
    foreach (['subscription', 'subscription_status'] as $type) {
        $operation = ($this->operation)('subscription');
        $operation['type'] = $type;
        $this->postJson('/api/v1/sync/batch', ['operations' => [$operation]])->assertOk()
            ->assertJsonPath('data.results.0.success', false)->assertJsonPath('data.results.0.error_code', 'access_denied');
    }
    $this->assertDatabaseCount('subscription_statuses', 0);
});