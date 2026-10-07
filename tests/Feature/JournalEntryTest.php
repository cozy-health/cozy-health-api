<?php

use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->journalUser = User::factory()->create();
    Sanctum::actingAs($this->journalUser);
    $this->journalPayload = ['type' => 'free', 'body' => 'A good day', 'is_draft' => false,
        'client_created_at' => '2026-10-07T10:00:00Z'];
});

it('creates a free entry and returns a complete non draft record', function () {
    $response = $this->postJson('/api/v1/journal-entries', $this->journalPayload)
        ->assertOk()->assertJsonPath('data.type', 'free')->assertJsonPath('data.body', 'A good day')
        ->assertJsonPath('data.is_draft', false)->assertJsonPath('data.user_id', $this->journalUser->id);
    $this->assertDatabaseHas('journal_entries', ['id' => $response->json('data.id'), 'is_draft' => false]);
});

it('creates a draft with the mobile fields and client id', function () {
    $id = (string) Str::uuid();
    $this->postJson('/api/v1/journal-entries', [...$this->journalPayload, 'id' => $id,
        'is_draft' => true, 'title' => 'Draft', 'tags' => ['calm'], 'word_count' => 3])
        ->assertOk()->assertJsonPath('data.id', $id)->assertJsonPath('data.is_draft', true)
        ->assertJsonPath('data.tags', ['calm']);
    $this->assertDatabaseHas('journal_entries', ['id' => $id, 'is_draft' => true]);
});

it('requires the create fields', function (string $field) {
    unset($this->journalPayload[$field]);
    $this->postJson('/api/v1/journal-entries', $this->journalPayload)->assertUnprocessable()->assertJsonValidationErrors($field);
})->with(['type', 'is_draft', 'client_created_at']);

it('validates type draft and tag values', function () {
    $this->postJson('/api/v1/journal-entries', [...$this->journalPayload,
        'type' => 'invalid', 'is_draft' => 'invalid', 'tags' => [123]])
        ->assertUnprocessable()->assertJsonValidationErrors(['type', 'is_draft', 'tags.0']);
});

it('supports a voice draft without a text body', function () {
    unset($this->journalPayload['body']);
    $this->postJson('/api/v1/journal-entries', [...$this->journalPayload, 'type' => 'voice', 'is_draft' => true])
        ->assertOk()->assertJsonPath('data.body', '')->assertJsonPath('data.is_draft', true);
});

it('updates title and draft state while preserving creation timestamps and body', function () {
    $created = $this->postJson('/api/v1/journal-entries', $this->journalPayload)->json('data');
    $this->travel(1)->minutes();
    $response = $this->patchJson('/api/v1/journal-entries/'.$created['id'], ['title' => 'Updated', 'is_draft' => true])
        ->assertOk()->assertJsonPath('data.title', 'Updated')->assertJsonPath('data.is_draft', true)
        ->assertJsonPath('data.body', $created['body'])->assertJsonPath('data.created_at', $created['created_at']);
    expect($response->json('data.updated_at'))->not->toBe($created['updated_at']);
});

it('rejects updates and deletes of another users entry', function () {
    $other = User::factory()->create();
    $id = (string) Str::uuid();
    JournalEntry::withoutGlobalScopes()->create(['id' => $id, 'user_id' => $other->id, 'type' => 'free', 'body' => 'Private']);
    $this->patchJson('/api/v1/journal-entries/'.$id, ['body' => 'Changed'])->assertNotFound();
    $this->deleteJson('/api/v1/journal-entries/'.$id)->assertNotFound();
    $this->getJson('/api/v1/journal-entries/'.$id)->assertNotFound();
    $this->assertDatabaseHas('journal_entries', ['id' => $id, 'body' => 'Private']);
});

it('ignores supplied ownership and server timestamps on create', function () {
    $this->postJson('/api/v1/journal-entries', [...$this->journalPayload,
        'user_id' => User::factory()->create()->id, 'created_at' => '2000-01-01'])
        ->assertOk()->assertJsonPath('data.user_id', $this->journalUser->id)->assertJsonPath('data.is_draft', false);
});

it('deletes the owners entry', function () {
    $id = $this->postJson('/api/v1/journal-entries', $this->journalPayload)->json('data.id');
    $this->deleteJson('/api/v1/journal-entries/'.$id)->assertOk()->assertExactJson(['message' => 'Journal entry deleted.']);
    $this->assertDatabaseMissing('journal_entries', ['id' => $id]);
});

it('returns boolean draft state in index and show', function () {
    $id = $this->postJson('/api/v1/journal-entries', [...$this->journalPayload, 'is_draft' => true])->json('data.id');
    $this->getJson('/api/v1/journal-entries')->assertOk()->assertJsonPath('data.data.0.is_draft', true);
    $this->getJson('/api/v1/journal-entries/'.$id)->assertOk()->assertJsonPath('data.is_draft', true);
});

it('persists draft state through the existing sync handler', function () {
    $id = (string) Str::uuid();
    $this->postJson('/api/v1/sync/batch', ['operations' => [[
        'type' => 'journal_entry', 'action' => 'upsert', 'client_operation_id' => 'journal:'.$id,
        'data' => [...$this->journalPayload, 'id' => $id, 'is_draft' => true],
    ]]])->assertOk()->assertJsonPath('data.results.0.success', true);
    $this->assertDatabaseHas('journal_entries', ['id' => $id, 'is_draft' => true]);
    $this->getJson('/api/v1/journal-entries/'.$id)->assertOk()->assertJsonPath('data.is_draft', true);
});

it('defaults existing model writes to non draft', function () {
    $entry = JournalEntry::create(['id' => (string) Str::uuid(), 'user_id' => $this->journalUser->id,
        'type' => 'free', 'body' => 'Existing writer']);
    expect($entry->refresh()->is_draft)->toBeFalse();
});
