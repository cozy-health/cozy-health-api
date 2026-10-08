<?php

namespace Tests\Feature\Provider;

use App\Models\JournalEntry;
use App\Models\MoodEntry;
use App\Models\ProviderAccessLog;
use App\Models\QuizAttempt;
use App\Models\User;
use Illuminate\Support\Str;

class ProviderClientReadTest extends ProviderDataTestCase
{
    public function test_provider_sees_only_linked_clients(): void
    {
        $provider = $this->provider();
        $user = User::factory()->create(['name' => 'Jane PrivateSurname']);
        $this->link($provider, $user);
        $this->link($this->provider(), User::factory()->create());
        $this->link($provider, User::factory()->create(), ['revoked_at' => now()]);
        $this->link($provider, User::factory()->create(), ['verified_at' => null]);
        $response = $this->asProvider($provider)->getJson('/api/v1/provider/clients')->assertOk()
            ->assertJsonCount(1)->assertJsonPath('0.name', 'Jane')->assertJsonPath('0.user_id', $user->id);
        $this->assertStringNotContainsString('PrivateSurname', $response->getContent());
        $this->getJson("/api/v1/provider/clients/{$user->id}")->assertOk()->assertJsonPath('name', 'Jane');
    }

    public function test_provider_cannot_see_unlinked_user(): void
    {
        $user = User::factory()->create();
        $this->asProvider($this->provider())->getJson("/api/v1/provider/clients/{$user->id}")->assertNotFound();
        $this->assertDatabaseCount('provider_access_logs', 0);
    }

    public function test_mood_access_requires_consent(): void
    {
        $this->consentRequired('moods');
    }

    public function test_journal_access_requires_consent(): void
    {
        $this->consentRequired('journals');
    }

    public function test_quiz_access_requires_consent(): void
    {
        $this->consentRequired('quizzes');
    }

    private function consentRequired(string $type): void
    {
        $provider = $this->provider();
        $user = User::factory()->create();
        $link = $this->link($provider, $user);
        $this->asProvider($provider)->getJson("/api/v1/provider/clients/{$user->id}/$type")->assertNotFound();
        $this->assertDatabaseCount('provider_access_logs', 0);
        $link->update(['consent_'.$type => true]);
        $this->getJson("/api/v1/provider/clients/{$user->id}/$type")->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_journal_bodies_never_returned(): void
    {
        $provider = $this->provider();
        $user = User::factory()->create();
        $this->link($provider, $user, ['consent_journals' => true]);
        $entry = JournalEntry::create(['id' => (string) Str::uuid(), 'user_id' => $user->id, 'type' => 'voice',
            'title' => 'Metadata title', 'body' => 'secret-body', 'transcription' => 'secret-transcription',
            'prompt_text' => 'secret-prompt', 'voice_url' => 'https://example.test/secret-audio', 'word_count' => 7]);
        JournalEntry::create(['id' => (string) Str::uuid(), 'user_id' => $user->id, 'type' => 'free', 'body' => 'draft', 'is_draft' => true]);
        $response = $this->asProvider($provider)->getJson("/api/v1/provider/clients/{$user->id}/journals")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $entry->id)->assertJsonPath('data.0.has_voice', true);
        $this->assertEqualsCanonicalizing(['id', 'title', 'type', 'word_count', 'has_voice', 'created_at'], array_keys($response->json('data.0')));
        foreach (['body', 'transcription', 'prompt_text', 'voice_url', 'secret-'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $response->getContent());
        }
    }

    public function test_consent_endpoint_always_allowed(): void
    {
        $provider = $this->provider();
        $user = User::factory()->create();
        $this->link($provider, $user);
        $this->asProvider($provider)->getJson("/api/v1/provider/clients/{$user->id}/consents")->assertOk()
            ->assertJsonPath('consent_flags', ['moods' => false, 'journals' => false, 'quizzes' => false, 'notes' => false]);
    }

    public function test_revoked_link_returns_404(): void
    {
        $provider = $this->provider();
        $user = User::factory()->create();
        $this->link($provider, $user, ['revoked_at' => now(), 'consent_moods' => true, 'consent_journals' => true, 'consent_quizzes' => true]);
        $this->asProvider($provider);
        foreach (['', '/moods', '/journals', '/quizzes', '/consents'] as $path) {
            $this->getJson("/api/v1/provider/clients/{$user->id}$path")->assertNotFound();
        }
        $this->assertDatabaseCount('provider_access_logs', 0);
    }

    public function test_every_read_logged(): void
    {
        $provider = $this->provider();
        $user = User::factory()->create();
        $this->link($provider, $user, ['consent_moods' => true, 'consent_journals' => true, 'consent_quizzes' => true]);
        $this->asProvider($provider)->getJson('/api/v1/provider/clients')->assertOk();
        foreach (['' => 'client', '/moods' => 'mood', '/journals' => 'journal', '/quizzes' => 'quiz', '/consents' => 'consent'] as $path => $resource) {
            $this->getJson("/api/v1/provider/clients/{$user->id}$path")->assertOk();
            $this->assertDatabaseHas('provider_access_logs', ['provider_id' => $provider->id, 'user_id' => $user->id,
                'resource_type' => $resource, 'ip_address' => '127.0.0.1']);
        }
        $this->assertDatabaseCount('provider_access_logs', 6);
        $this->assertSame(2, ProviderAccessLog::where('user_id', $user->id)->where('resource_type', 'client')->count());
    }

    public function test_reads_are_patient_scoped_paginated_filtered_and_exclude_deleted_entries(): void
    {
        $provider = $this->provider();
        $user = User::factory()->create();
        $other = User::factory()->create();
        $this->link($provider, $user, ['consent_moods' => true, 'consent_quizzes' => true]);
        foreach ([$user, $user, $other] as $owner) {
            MoodEntry::create(['id' => (string) Str::uuid(), 'user_id' => $owner->id, 'mood' => 'happy', 'intensity' => 3,
                'client_created_at' => '2026-10-01 12:00:00']);
        }
        MoodEntry::create(['id' => (string) Str::uuid(), 'user_id' => $user->id, 'mood' => 'sad', 'intensity' => 2, 'is_deleted' => true]);
        $quiz = QuizAttempt::create(['id' => (string) Str::uuid(), 'user_id' => $user->id, 'quiz_slug' => 'phq-9',
            'quiz_title' => 'Private title', 'answers' => ['private'], 'score' => 3, 'interpretation' => 'minimal', 'completed_at' => now()]);
        $this->asProvider($provider)->getJson("/api/v1/provider/clients/{$user->id}/moods?per_page=1&from=2026-10-01&to=2026-10-01")
            ->assertOk()->assertJsonPath('total', 2)->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/provider/clients/{$user->id}/moods?from=2026-09-01&to=2026-09-02")
            ->assertOk()->assertJsonPath('total', 0);
        $response = $this->getJson("/api/v1/provider/clients/{$user->id}/quizzes")->assertOk()->assertJsonPath('data.0.id', $quiz->id);
        $this->assertEqualsCanonicalizing(['id', 'quiz_slug', 'score', 'interpretation', 'completed_at'], array_keys($response->json('data.0')));
        $this->getJson("/api/v1/provider/clients/{$user->id}/moods?per_page=10000")->assertUnprocessable();
        $this->getJson("/api/v1/provider/clients/{$user->id}/moods?to=2026-10-01")->assertOk();
    }

    public function test_patient_token_and_unauthenticated_calls_cannot_read_clients(): void
    {
        $this->getJson('/api/v1/provider/clients')->assertUnauthorized();
        $user = User::factory()->create();
        $this->withToken($user->createToken('patient')->plainTextToken)->getJson('/api/v1/provider/clients')->assertUnauthorized();
    }
}
