<?php

namespace Tests\Feature;

use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AccountOfflineAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    private function login(User $user): void
    {
        app('auth')->forgetGuards();
        $this->withToken($user->createToken('device')->plainTextToken);
    }

    public function test_direct_and_offline_journal_edits_preserve_omitted_crisis_flags_and_accept_explicit_clearing(): void
    {
        $user = User::factory()->create();
        $this->login($user);
        $id = $this->postJson('/api/v1/journal-entries', ['type' => 'free', 'body' => 'Synthetic neutral note',
            'is_draft' => false, 'client_created_at' => now()->toIso8601String(), 'is_crisis_flagged' => true])->assertOk()->json('data.id');
        $operation = ['client_operation_id' => (string) Str::uuid(), 'type' => 'journal_entry', 'action' => 'upsert', 'data' => ['id' => $id, 'body' => 'Edited offline']];
        $this->postJson('/api/v1/sync/batch', ['operations' => [$operation]])->assertOk()->assertJsonPath('data.results.0.success', true);
        $this->assertTrue(JournalEntry::findOrFail($id)->is_crisis_flagged);
        $this->patchJson('/api/v1/journal-entries/'.$id, ['is_crisis_flagged' => false])->assertOk()->assertJsonPath('data.is_crisis_flagged', false);
        $operation['client_operation_id'] = (string) Str::uuid();
        $operation['data']['is_crisis_flagged'] = true;
        $this->postJson('/api/v1/sync/batch', ['operations' => [$operation]])->assertOk()->assertJsonPath('data.results.0.success', true);
        $this->getJson('/api/v1/journal-entries/'.$id)->assertOk()->assertJsonPath('data.is_crisis_flagged', true);
    }

    public function test_an_old_accounts_record_cannot_be_read_or_overwritten_by_another_accounts_sync(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        $this->login($first);
        $id = $this->postJson('/api/v1/journal-entries', ['type' => 'free', 'body' => 'Account A private record',
            'is_draft' => true, 'client_created_at' => now()->toIso8601String()])->assertOk()->json('data.id');
        $this->login($second);
        $this->getJson('/api/v1/journal-entries/'.$id)->assertNotFound();
        $this->postJson('/api/v1/sync/batch', ['operations' => [['client_operation_id' => (string) Str::uuid(), 'type' => 'journal_entry',
            'action' => 'upsert', 'data' => ['id' => $id, 'body' => 'Must not replace A']]]])->assertOk()->assertJsonPath('data.results.0.success', false);
        $this->login($first);
        $this->getJson('/api/v1/journal-entries/'.$id)->assertOk()->assertJsonPath('data.body', 'Account A private record');
    }
}
