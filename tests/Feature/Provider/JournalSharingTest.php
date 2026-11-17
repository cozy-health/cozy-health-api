<?php

namespace Tests\Feature\Provider;

use App\Models\{JournalEntry, JournalShare, Provider, ProviderMessage, User};
use App\Services\SyncRecordWriter;
use Illuminate\Support\Facades\{DB, Event};
use Illuminate\Support\Str;

class JournalSharingTest extends ProviderDataTestCase
{
    private function actor(User|Provider $actor): static
    {
        // A multi-request test must drop the prior RequestGuard's cached actor.
        $this->app['auth']->forgetGuards();
        return $this->withToken($actor->createToken('test')->plainTextToken);
    }

    private function setupShare(): array
    {
        $provider = $this->provider();
        $user = User::factory()->create();
        $link = $this->link($provider, $user, ['consent_journals' => true]);
        $entry = JournalEntry::create(['id' => (string) Str::uuid(), 'user_id' => $user->id,
            'type' => 'free', 'title' => 'Fictional reflection', 'body' => 'A fictional private reflection.', 'is_draft' => false]);
        $this->actor($user);
        return [$provider, $user, $link, $entry, "/api/v1/provider-conversations/{$link->id}/journal-shares"];
    }

    private function payload(string $path, JournalEntry $entry): array
    {
        $preview = $this->getJson("$path/preview?journal_id={$entry->id}")->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');
        return ['journal_id' => $entry->id, 'expected_revision' => $preview->json('revision'),
            'client_id' => (string) Str::uuid(), 'confirmed' => true];
    }

    public function test_explicit_encrypted_snapshot_is_recipient_scoped_and_not_in_message_cache(): void
    {
        [$provider, $user, $link, $entry, $path] = $this->setupShare();
        $data = $this->payload($path, $entry);
        $id = $this->postJson($path, $data)->assertCreated()->json('share_id');
        $this->assertStringNotContainsString($entry->body, DB::table('journal_shares')->value('snapshot'));
        $this->assertStringNotContainsString($entry->body, json_encode(JournalShare::first()->toArray()));
        $this->getJson("/api/v1/provider-conversations/{$link->id}/messages")->assertOk()
            ->assertJsonPath('data.0.records.0.type', 'journal_shares')
            ->assertJsonMissingPath('data.0.records.0.data.body')
            ->assertJsonMissingPath('data.0.records.0.data.title');
        $this->actor($provider)->getJson("/api/v1/provider/conversations/{$link->id}/journal-shares/$id")
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('entry.body', $entry->body)->assertJsonPath('later_edits_shared', false);
        $this->assertDatabaseHas('provider_access_logs', ['resource_type' => 'journal_share', 'resource_id' => $id]);
        $this->actor($this->provider())->getJson("/api/v1/provider/conversations/{$link->id}/journal-shares/$id")->assertNotFound();
        $this->actor(User::factory()->create())
            ->getJson("$path/$id")->assertNotFound();
    }

    public function test_retry_is_idempotent_and_later_edits_require_a_new_confirmation(): void
    {
        [$provider, $user, $link, $entry, $path] = $this->setupShare();
        $data = $this->payload($path, $entry);
        $id = $this->postJson($path, $data)->assertCreated()->json('share_id');
        $entry->update(['body' => 'Later private edit.']);
        $this->postJson($path, $data)->assertOk()->assertJsonPath('share_id', $id)->assertJsonPath('replayed', true);
        $this->getJson("$path/$id")->assertJsonPath('entry.body', 'A fictional private reflection.');
        $data['client_id'] = (string) Str::uuid();
        $this->postJson($path, $data)->assertConflict();
        $new = $this->payload($path, $entry);
        $newId = $this->postJson($path, $new)->assertCreated()->json('share_id');
        $this->assertNotEquals($id, $newId);
        $this->getJson("$path/$newId")->assertJsonPath('entry.body', 'Later private edit.');
        $this->assertDatabaseCount('journal_shares', 2);
        $this->assertDatabaseCount('provider_messages', 2);
    }

    public function test_preview_changes_and_reused_request_ids_do_not_leak_or_send(): void
    {
        [, , , $entry, $path] = $this->setupShare();
        $data = $this->payload($path, $entry);
        $entry->update(['title' => 'Changed title']);
        $this->postJson($path, $data)->assertConflict();
        $this->assertDatabaseCount('journal_shares', 0);
        $this->assertDatabaseCount('provider_messages', 0);
        $data = $this->payload($path, $entry);
        $this->postJson($path, $data)->assertCreated();
        $data['expected_revision'] = str_repeat('0', 64);
        $this->postJson($path, $data)->assertConflict();
    }

    public function test_confirmation_and_current_saved_written_entry_are_required(): void
    {
        [, , , $entry, $path] = $this->setupShare();
        $data = $this->payload($path, $entry);
        $this->postJson($path, [...$data, 'confirmed' => false])->assertUnprocessable();
        $entry->update(['is_draft' => true]);
        $this->getJson("$path/preview?journal_id={$entry->id}")->assertUnprocessable();
        $this->postJson($path, $data)->assertUnprocessable();
        $entry->update(['is_draft' => false, 'type' => 'voice']);
        $this->getJson("$path/preview?journal_id={$entry->id}")->assertUnprocessable();
        $entry->update(['type' => 'free', 'body' => '  ']);
        $this->getJson("$path/preview?journal_id={$entry->id}")->assertUnprocessable();
        $this->assertDatabaseCount('journal_shares', 0);
    }

    public function test_foreign_entry_and_unconnected_provider_are_rejected(): void
    {
        [, , $link, $entry, $path] = $this->setupShare();
        $data = $this->payload($path, $entry);
        $entry->update(['user_id' => User::factory()->create()->id]);
        $this->getJson("$path/preview?journal_id={$entry->id}")->assertNotFound();
        $this->postJson($path, $data)->assertNotFound();
        $link->update(['verified_at' => null]);
        $this->getJson("$path/preview?journal_id={$entry->id}")->assertNotFound();
    }

    public function test_legacy_journal_references_remain_metadata_only(): void
    {
        [$provider, , $link, $entry] = $this->setupShare();
        $this->postJson("/api/v1/provider-conversations/{$link->id}/messages", [
            'client_id' => (string) Str::uuid(), 'records' => [['type' => 'journals', 'id' => $entry->id]],
        ])->assertCreated();
        $response = $this->actor($provider)->getJson("/api/v1/provider/conversations/{$link->id}/messages")->assertOk();
        $this->assertStringNotContainsString($entry->body, $response->getContent());
        $this->assertDatabaseCount('journal_shares', 0);
    }

    public function test_withdrawal_purges_text_and_retries_cannot_reactivate_it(): void
    {
        [$provider, $user, $link, $entry, $path] = $this->setupShare();
        $data = $this->payload($path, $entry);
        $id = $this->postJson($path, $data)->assertCreated()->json('share_id');
        $this->deleteJson("$path/$id")->assertOk();
        $this->deleteJson("$path/$id")->assertOk();
        $this->assertNull(DB::table('journal_shares')->value('snapshot'));
        $this->getJson("$path/$id")->assertNotFound();
        $this->postJson($path, $data)->assertConflict();
        $this->actor($provider)->getJson("/api/v1/provider/conversations/{$link->id}/messages")
            ->assertJsonPath('data.0.records.0.unavailable', true);
    }

    public function test_disabling_and_reenabling_category_cannot_restore_old_text(): void
    {
        [$provider, , $link, $entry, $path] = $this->setupShare();
        $data = $this->payload($path, $entry);
        $id = $this->postJson($path, $data)->assertCreated()->json('share_id');
        $this->patchJson("/api/v1/me/provider/{$link->id}/consent", ['consent_type' => 'journals', 'value' => false])->assertOk();
        $this->getJson("$path/$id")->assertNotFound();
        $this->postJson($path, $data)->assertForbidden();
        $this->patchJson("/api/v1/me/provider/{$link->id}/consent", ['consent_type' => 'journals', 'value' => true])->assertOk();
        $this->getJson("$path/$id")->assertNotFound();
        $this->assertNull(DB::table('journal_shares')->value('snapshot'));
    }

    public function test_disconnect_and_account_deletion_remove_shared_text(): void
    {
        [, $user, $link, $entry, $path] = $this->setupShare();
        $this->postJson($path, $this->payload($path, $entry))->assertCreated();
        $this->deleteJson("/api/v1/me/provider/{$link->id}")->assertOk();
        $this->assertNull(DB::table('journal_shares')->value('snapshot'));
        $user->forceDelete();
        $this->assertDatabaseCount('journal_shares', 0);
    }

    public function test_direct_and_sync_deletion_permanently_revoke_shared_copies(): void
    {
        [, $user, , $entry, $path] = $this->setupShare();
        $id = $this->postJson($path, $this->payload($path, $entry))->assertCreated()->json('share_id');
        $entry->delete();
        $this->assertNull(DB::table('journal_shares')->value('snapshot'));
        $entry->restore();
        $this->getJson("$path/$id")->assertNotFound();
        $this->postJson($path, $this->payload($path, $entry))->assertCreated();
        DB::transaction(fn () => app(SyncRecordWriter::class)->delete(JournalEntry::class, 'journal_entry', $user, $entry->id));
        $this->assertSame(0, JournalShare::whereNull('revoked_at')->count());
    }

    public function test_sync_tombstone_and_foreign_withdrawal(): void
    {
        [, $user, , $entry, $path] = $this->setupShare();
        $id = $this->postJson($path, $this->payload($path, $entry))->assertCreated()->json('share_id');
        $other = User::factory()->create();
        $this->actor($other)->deleteJson("$path/$id")->assertNotFound();
        $response = $this->actor($user)->postJson('/api/v1/sync/batch', ['operations' => [[
            'type' => 'journal_entry', 'action' => 'upsert', 'data' => ['id' => $entry->id, 'is_deleted' => true],
        ]]])->assertOk();
        $this->assertArrayNotHasKey('error', $response->json('data.results.0'));
        $response->assertJsonPath('data.results.0.success', true);
        $this->assertNull(DB::table('journal_shares')->value('snapshot'));
    }

    public function test_failed_message_creation_rolls_back_snapshot(): void
    {
        [, , , $entry, $path] = $this->setupShare();
        $data = $this->payload($path, $entry);
        Event::listen('eloquent.creating: '.ProviderMessage::class, fn () => throw new \RuntimeException('Synthetic failure'));
        try {
            $this->postJson($path, $data)->assertStatus(500);
        } finally {
            Event::forget('eloquent.creating: '.ProviderMessage::class);
        }
        $this->assertDatabaseCount('journal_shares', 0);
        $this->assertDatabaseCount('provider_messages', 0);
    }

    public function test_grants_cannot_be_forged_through_normal_messages_or_another_owned_link(): void
    {
        [$provider, $user, $link, $entry, $path] = $this->setupShare();
        $id = $this->postJson($path, $this->payload($path, $entry))->assertCreated()->json('share_id');
        $otherLink = $this->link($this->provider(), $user, ['consent_journals' => true]);
        $this->getJson("/api/v1/provider-conversations/{$otherLink->id}/journal-shares/$id")->assertNotFound();
        $this->postJson("/api/v1/provider-conversations/{$link->id}/messages", [
            'client_id' => (string) Str::uuid(), 'records' => [['type' => 'journal_shares', 'id' => $id]],
        ])->assertUnprocessable();
        $this->actor($provider)->postJson($path, ['journal_id' => $entry->id, 'expected_revision' => str_repeat('0', 64),
            'client_id' => (string) Str::uuid(), 'confirmed' => true])->assertUnauthorized();
        $this->assertDatabaseCount('journal_shares', 1);
    }

    public function test_disabled_provider_and_unknown_session_cannot_read(): void
    {
        [$provider, , $link, $entry, $path] = $this->setupShare();
        $id = $this->postJson($path, $this->payload($path, $entry))->assertCreated()->json('share_id');
        $provider->update(['is_active' => false]);
        $this->getJson("$path/$id")->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', '')->getJson("$path/$id")->assertUnauthorized();
    }

    public function test_user_soft_delete_purges_and_restoration_does_not_reactivate(): void
    {
        [, $user, , $entry, $path] = $this->setupShare();
        $id = $this->postJson($path, $this->payload($path, $entry))->assertCreated()->json('share_id');
        $user->delete();
        $this->assertNull(DB::table('journal_shares')->value('snapshot'));
        $user->restore();
        $this->actor($user)->getJson("$path/$id")->assertNotFound();
    }
}
