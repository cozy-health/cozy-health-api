<?php

use App\Models\{AppNotification, ChatConversation, MoodEntry, SafetyPlan, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->owner = User::factory()->create();
    Sanctum::actingAs($this->owner);
});

it('persists a partial direct mood edit and its deletion', function () {
    $id = (string) Str::uuid();
    $this->postJson('/api/v1/mood-entries', ['id' => $id, 'mood' => 'good', 'intensity' => 5, 'note' => 'Keep'])
        ->assertOk();
    $this->patchJson('/api/v1/mood-entries/'.$id, ['intensity' => 7, 'user_id' => 999])
        ->assertOk()->assertJsonPath('data.intensity', 7)->assertJsonPath('data.note', 'Keep');
    $this->assertDatabaseHas('mood_entries', ['id' => $id, 'user_id' => $this->owner->id, 'intensity' => 7]);
    $this->patchJson('/api/v1/mood-entries/'.$id, ['intensity' => 11])->assertUnprocessable();
    $this->deleteJson('/api/v1/mood-entries/'.$id)->assertOk();
    $this->assertSoftDeleted('mood_entries', ['id' => $id]);
    $this->getJson('/api/v1/mood-entries/'.$id)->assertNotFound();
});

it('does not edit delete or overwrite another accounts mood', function () {
    $other = User::factory()->create();
    $id = (string) Str::uuid();
    MoodEntry::withoutGlobalScopes()->create(['id' => $id, 'user_id' => $other->id, 'mood' => 'good', 'intensity' => 5]);
    $this->patchJson('/api/v1/mood-entries/'.$id, ['intensity' => 7])->assertNotFound();
    $this->deleteJson('/api/v1/mood-entries/'.$id)->assertNotFound();
    $this->postJson('/api/v1/mood-entries', ['id' => $id, 'mood' => 'good', 'intensity' => 7])->assertConflict();
    $this->assertDatabaseHas('mood_entries', ['id' => $id, 'user_id' => $other->id, 'intensity' => 5, 'deleted_at' => null]);
});

it('persists safety plans through direct and sync writes with shared validation', function () {
    $this->putJson('/api/v1/safety-plan', ['warning_signs' => ['Racing thoughts'], 'is_complete' => false])
        ->assertOk()->assertJsonPath('data.warning_signs.0', 'Racing thoughts');
    $id = SafetyPlan::first()->id;
    $this->putJson('/api/v1/safety-plan', ['coping_strategies' => ['Breathing']])->assertOk();
    $this->getJson('/api/v1/safety-plan')->assertOk()->assertJsonPath('data.id', $id)
        ->assertJsonPath('data.warning_signs.0', 'Racing thoughts')->assertJsonPath('data.coping_strategies.0', 'Breathing');
    $this->putJson('/api/v1/safety-plan', ['warning_signs' => 'invalid'])->assertUnprocessable();
    $this->postJson('/api/v1/sync/batch', ['operations' => [[
        'type' => 'safety_plan', 'action' => 'upsert', 'data' => ['id' => $id, 'warning_signs' => 'invalid'],
    ]]])->assertOk()->assertJsonPath('data.results.0.success', false)->assertJsonPath('data.results.0.error_code', 'invalid_payload');
    expect(SafetyPlan::count())->toBe(1);
});

it('keeps safety plan ownership server managed', function () {
    $other = User::factory()->create();
    $otherPlan = SafetyPlan::withoutGlobalScopes()->create(['id' => (string) Str::uuid(), 'user_id' => $other->id, 'warning_signs' => ['Private']]);
    $this->putJson('/api/v1/safety-plan', ['id' => $otherPlan->id, 'user_id' => $other->id, 'warning_signs' => ['Changed']])->assertConflict();
    expect($otherPlan->fresh()->warning_signs)->toBe(['Private']);
});

function integrityNotification(User $owner): AppNotification
{
    return AppNotification::withoutGlobalScopes()->create(['id' => (string) Str::uuid(), 'user_id' => $owner->id,
        'type' => 'system', 'title' => 'Server title', 'body' => 'Server body', 'is_read' => false]);
}

it('updates only notification read state and deletes owned notifications', function () {
    $notification = integrityNotification($this->owner);
    $this->patchJson('/api/v1/notifications/'.$notification->id, ['is_read' => true, 'title' => 'Forged', 'user_id' => 999])
        ->assertOk()->assertJsonPath('data.is_read', true)->assertJsonPath('data.title', 'Server title');
    expect($notification->fresh()->read_at)->not->toBeNull();
    $this->patchJson('/api/v1/notifications/'.$notification->id, ['is_read' => false])->assertOk();
    expect($notification->fresh()->read_at)->toBeNull();
    $this->deleteJson('/api/v1/notifications/'.$notification->id)->assertOk();
    $this->assertSoftDeleted('app_notifications', ['id' => $notification->id]);
});

it('rejects client-created notifications and protects another accounts notifications', function () {
    $this->postJson('/api/v1/notifications', ['title' => 'Forged'])->assertForbidden();
    $other = integrityNotification(User::factory()->create());
    $this->patchJson('/api/v1/notifications/'.$other->id, ['is_read' => true])->assertNotFound();
    $this->deleteJson('/api/v1/notifications/'.$other->id)->assertNotFound();
    $this->postJson('/api/v1/sync/batch', ['operations' => [[
        'type' => 'app_notification', 'action' => 'upsert', 'data' => ['id' => (string) Str::uuid(), 'title' => 'Forged'],
    ]]])->assertOk()->assertJsonPath('data.results.0.success', false)->assertJsonPath('data.results.0.error_code', 'access_denied');
    expect($other->fresh()->is_read)->toBeFalse();
});

it('syncs only the read state of an existing owned notification', function () {
    $notification = integrityNotification($this->owner);
    $this->postJson('/api/v1/sync/batch', ['operations' => [[
        'type' => 'app_notification', 'action' => 'upsert', 'data' => ['id' => $notification->id,
            'is_read' => true, 'title' => 'Forged', 'body' => 'Forged', 'read_at' => '2000-01-01T00:00:00Z'],
    ]]])->assertOk()->assertJsonPath('data.results.0.success', true);
    $notification->refresh();
    expect($notification->title)->toBe('Server title');
    expect($notification->body)->toBe('Server body');
    expect($notification->is_read)->toBeTrue();
    expect($notification->read_at->isToday())->toBeTrue();
});

it('returns gone for retired assistant mutations while preserving historical reads', function () {
    $conversation = ChatConversation::create(['id' => (string) Str::uuid(), 'user_id' => $this->owner->id, 'title' => 'History']);
    $this->getJson('/api/v1/conversations/'.$conversation->id)->assertOk();
    $this->postJson('/api/v1/conversations', ['title' => 'New'])->assertGone();
    $this->patchJson('/api/v1/conversations/'.$conversation->id, ['title' => 'Changed'])->assertGone();
    $this->deleteJson('/api/v1/conversations/'.$conversation->id)->assertGone();
    foreach (['chat_conversation', 'chat_message'] as $type) {
        $this->postJson('/api/v1/sync/batch', ['operations' => [[
            'type' => $type, 'action' => 'upsert', 'data' => ['id' => (string) Str::uuid()],
        ]]])->assertOk()->assertJsonPath('data.results.0.success', false)->assertJsonPath('data.results.0.error_code', 'gone');
    }
    expect($conversation->fresh()->title)->toBe('History');
});
