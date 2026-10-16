<?php

namespace Tests\Feature\Provider;

use App\Models\JournalEntry;
use App\Models\MoodEntry;
use App\Models\ProviderMessage;
use App\Models\ProviderNote;
use App\Models\QuizAttempt;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProviderMessageTest extends ProviderDataTestCase
{
    private function patient(User $user): static
    {
        return $this->withToken($user->createToken('test')->plainTextToken);
    }

    public function test_both_sides_send_read_and_retry_without_duplicates(): void
    {
        $provider = $this->provider();
        $user = User::factory()->create();
        $link = $this->link($provider, $user);
        $path = "/api/v1/provider-conversations/{$link->id}";
        $payload = ['client_id' => (string) Str::uuid(), 'content' => 'Hello provider'];
        $this->patient($user)->postJson("$path/messages", $payload)->assertCreated();
        $this->postJson("$path/messages", $payload)->assertOk();
        $this->assertDatabaseCount('provider_messages', 1);
        $this->assertNotEquals('Hello provider', DB::table('provider_messages')->value('content'));
        $this->asProvider($provider)->getJson('/api/v1/provider/conversations')->assertOk()->assertJsonPath('0.unread', 1);
        $providerPath = "/api/v1/provider/conversations/{$link->id}";
        $this->getJson("$providerPath/messages")->assertOk()->assertJsonPath('data.0.content', 'Hello provider');
        $this->postJson("$providerPath/read", ['through' => ProviderMessage::first()->id])->assertOk();
        $this->getJson('/api/v1/provider/conversations')->assertJsonPath('0.unread', 0);
        $this->postJson("$providerPath/messages", ['client_id' => (string) Str::uuid(), 'content' => 'Hello patient'])->assertCreated();
        $this->patient($user)->getJson("$path/messages")->assertJsonCount(2, 'data')->assertJsonPath('data.1.sender', 'provider');
    }

    public function test_unrelated_and_revoked_links_are_inaccessible(): void
    {
        $provider = $this->provider();
        $user = User::factory()->create();
        $link = $this->link($provider, $user);
        $path = "/api/v1/provider/conversations/{$link->id}";
        $this->asProvider($this->provider())->getJson("$path/messages")->assertNotFound();
        $this->patient(User::factory()->create())->getJson("/api/v1/provider-conversations/{$link->id}/messages")->assertNotFound();
        $link->update(['revoked_at' => now()]);
        $this->asProvider($provider)->getJson("$path/messages")->assertNotFound();
        $this->postJson("$path/messages", ['client_id' => (string) Str::uuid(), 'content' => 'No'])->assertNotFound();
    }

    public function test_shared_records_follow_consent_and_deletion_and_ownership(): void
    {
        $provider = $this->provider();
        $user = User::factory()->create();
        $link = $this->link($provider, $user, ['consent_moods' => true, 'consent_journals' => true, 'consent_notes' => true]);
        $mood = MoodEntry::create(['id' => (string) Str::uuid(), 'user_id' => $user->id, 'mood' => 'happy', 'intensity' => 3]);
        $journal = JournalEntry::create(['id' => (string) Str::uuid(), 'user_id' => $user->id, 'type' => 'text', 'title' => 'My day', 'body' => 'Private body', 'is_draft' => false]);
        $note = ProviderNote::create(['provider_id' => $provider->id, 'user_id' => $user->id, 'body' => 'Care note']);
        $path = "/api/v1/provider-conversations/{$link->id}";
        $this->patient($user)->getJson("$path/records?type=moods")->assertOk()->assertJsonPath('data.0.id', $mood->id);
        $this->postJson("$path/messages", ['client_id' => (string) Str::uuid(), 'records' => [
            ['type' => 'moods', 'id' => $mood->id], ['type' => 'journals', 'id' => $journal->id], ['type' => 'notes', 'id' => (string) $note->id],
        ]])->assertCreated();
        $providerPath = "/api/v1/provider/conversations/{$link->id}/messages";
        $response = $this->asProvider($provider)->getJson($providerPath)->assertOk()->assertJsonPath('data.0.records.0.data.mood', 'happy');
        $this->assertStringNotContainsString('Private body', $response->getContent());
        $link->update(['consent_moods' => false]);
        $journal->delete();
        $this->getJson($providerPath)->assertJsonPath('data.0.records.0.unavailable', true)->assertJsonPath('data.0.records.1.unavailable', true);
        $this->patient($user)->postJson("$path/messages", ['client_id' => (string) Str::uuid(), 'records' => [['type' => 'moods', 'id' => $mood->id]]])->assertUnprocessable();
        $other = ProviderNote::create(['provider_id' => $provider->id, 'user_id' => User::factory()->create()->id, 'body' => 'Other patient']);
        $this->postJson("$path/messages", ['client_id' => (string) Str::uuid(), 'records' => [['type' => 'notes', 'id' => (string) $other->id]]])->assertUnprocessable();
    }

    public function test_pagination_and_validation(): void
    {
        $provider = $this->provider();
        $user = User::factory()->create();
        $link = $this->link($provider, $user);
        for ($i = 0; $i < 52; $i++) {
            ProviderMessage::create(['provider_client_link_id' => $link->id, 'sender' => 'patient', 'client_id' => (string) Str::uuid(), 'content' => "Message $i"]);
        }
        $path = "/api/v1/provider-conversations/{$link->id}/messages";
        $response = $this->patient($user)->getJson($path)->assertOk()->assertJsonCount(50, 'data')->assertJsonPath('has_more', true);
        $this->getJson($path.'?before='.$response->json('data.0.id'))->assertJsonCount(2, 'data')->assertJsonPath('has_more', false);
        $this->postJson($path, ['client_id' => (string) Str::uuid(), 'content' => '   '])->assertUnprocessable();
        $this->postJson($path, ['client_id' => (string) Str::uuid(), 'content' => str_repeat('x', 5001)])->assertUnprocessable();
    }

    public function test_selected_quiz_creates_a_revocable_provider_grant(): void
    {
        $provider = $this->provider();
        $user = User::factory()->create();
        $link = $this->link($provider, $user, ['consent_quizzes' => true]);
        $quiz = QuizAttempt::create(['id' => (string) Str::uuid(), 'user_id' => $user->id,
            'quiz_slug' => 'phq-9', 'quiz_title' => 'Assessment', 'answers' => [], 'score' => 3,
            'interpretation' => 'minimal', 'completed_at' => now(), 'validated_at' => now()]);
        $path = "/api/v1/provider-conversations/{$link->id}";
        $this->patient($user)->getJson("$path/records?type=quizzes")->assertOk()->assertJsonPath('data.0.id', $quiz->id);
        $this->postJson("$path/messages", ['client_id' => (string) Str::uuid(), 'records' => [['type' => 'quizzes', 'id' => $quiz->id]]])->assertCreated();
        $providerPath = "/api/v1/provider/conversations/{$link->id}/messages";
        $this->asProvider($provider)->getJson($providerPath)->assertJsonPath('data.0.records.0.data.score', 3);
        DB::table('quiz_shares')->where('quiz_attempt_id', $quiz->id)->update(['revoked_at' => now()]);
        $this->getJson($providerPath)->assertJsonPath('data.0.records.0.unavailable', true);
    }
}
