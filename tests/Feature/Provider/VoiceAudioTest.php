<?php

namespace Tests\Feature\Provider;

use App\Models\AudioAttachment;
use App\Models\JournalEntry;
use App\Models\ProviderMessage;
use App\Models\User;
use App\Services\AudioAttachmentService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class VoiceAudioTest extends ProviderDataTestCase
{
    public function withToken($token, $type = 'Bearer')
    {
        $this->app['auth']->forgetGuards();
        return parent::withToken($token, $type);
    }

    private function upload(string $purpose, ?int $link = null, ?string $clientId = null, string $prefix = '/api/v1')
    {
        return $this->post($prefix.'/audio-attachments', [
            'client_id' => $clientId ?? (string) Str::uuid(), 'purpose' => $purpose,
            'provider_client_link_id' => $link, 'duration' => 12,
            'audio' => new UploadedFile(base_path('tests/Fixtures/voice.m4a'), 'voice.m4a', 'audio/mp4', null, true),
        ], ['Accept' => 'application/json']);
    }

    public function test_private_journal_upload_sync_retry_playback_and_delete(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $this->withToken($user->createToken('voice')->plainTextToken);
        $clientId = (string) Str::uuid();
        $id = $this->upload('journal', clientId: $clientId)->assertCreated()->json('id');
        $this->upload('journal', clientId: $clientId)->assertOk()->assertJsonPath('id', $id);
        $this->assertDatabaseCount('audio_attachments', 1);
        $audio = AudioAttachment::findOrFail($id);
        Storage::disk('local')->assertExists($audio->storage_key);
        $entryId = (string) Str::uuid();
        $this->postJson('/api/v1/sync/batch', ['operations' => [[
            'client_operation_id' => (string) Str::uuid(), 'type' => 'journal_entry', 'action' => 'upsert',
            'data' => ['id' => $entryId, 'type' => 'voice', 'body' => '', 'is_draft' => false,
                'client_created_at' => now()->toISOString(), 'voice_url' => '/audio-attachments/'.$id],
        ]]])->assertOk()->assertJsonPath('data.results.0.success', true);
        $this->assertDatabaseHas('journal_entries', ['id' => $entryId, 'audio_attachment_id' => $id]);
        $this->get('/api/v1/audio-attachments/'.$id)->assertOk()->assertHeader('Content-Type', 'audio/mp4');
        $this->get('/api/v1/audio-attachments/'.$id, ['Range' => 'bytes=0-3'])->assertStatus(206)->assertHeader('Content-Length', '4');
        $this->get('/api/v1/audio-attachments/'.$id, ['Range' => 'bytes=999999999-'])->assertStatus(416);
        $this->withToken(User::factory()->create()->createToken('other')->plainTextToken)
            ->getJson('/api/v1/audio-attachments/'.$id)->assertNotFound();
        $this->withToken($user->createToken('voice2')->plainTextToken);
        $this->deleteJson('/api/v1/journal-entries/'.$entryId)->assertSuccessful();
        $this->getJson('/api/v1/audio-attachments/'.$id)->assertNotFound();
        $this->travel(8)->days();
        $this->assertSame(1, app(AudioAttachmentService::class)->cleanup());
        Storage::disk('local')->assertMissing($audio->storage_key);
    }

    public function test_both_sides_send_audio_and_retries_do_not_duplicate_messages(): void
    {
        Storage::fake('local');
        $provider = $this->provider(); $user = User::factory()->create();
        $link = $this->link($provider, $user);
        $this->withToken($user->createToken('patient')->plainTextToken);
        $id = $this->upload('message', $link->id)->assertCreated()->json('id');
        $payload = ['client_id' => (string) Str::uuid(), 'audio_attachment_id' => $id];
        $this->postJson("/api/v1/provider-conversations/{$link->id}/messages", $payload)->assertCreated();
        $this->postJson("/api/v1/provider-conversations/{$link->id}/messages", $payload)->assertOk();
        $this->assertDatabaseCount('provider_messages', 1);
        $this->asProvider($provider)->getJson("/api/v1/provider/conversations/{$link->id}/messages")
            ->assertOk()->assertJsonPath('data.0.audio.id', $id)->assertJsonPath('data.0.audio.duration', 12);
        $this->get('/api/v1/provider/audio-attachments/'.$id)->assertOk();
        $reply = $this->upload('message', $link->id, prefix: '/api/v1/provider')->assertCreated()->json('id');
        // An unsent provider recording is unavailable to the patient.
        $this->withToken($user->createToken('patient2')->plainTextToken)->getJson('/api/v1/audio-attachments/'.$reply)->assertNotFound();
        $this->asProvider($provider)->postJson("/api/v1/provider/conversations/{$link->id}/messages", [
            'client_id' => (string) Str::uuid(), 'audio_attachment_id' => $reply,
        ])->assertCreated();
        $this->withToken($user->createToken('patient3')->plainTextToken)->get('/api/v1/audio-attachments/'.$reply)->assertOk();
        $link->update(['revoked_at' => now()]);
        $this->getJson('/api/v1/audio-attachments/'.$reply)->assertNotFound();
        $this->asProvider($provider)->getJson('/api/v1/provider/audio-attachments/'.$id)->assertNotFound();
    }

    public function test_rejects_wrong_owner_purpose_conversation_and_invalid_uploads(): void
    {
        Storage::fake('local');
        $provider = $this->provider(); $user = User::factory()->create();
        $link = $this->link($provider, $user); $otherLink = $this->link($this->provider(), $user);
        $this->withToken($user->createToken('patient')->plainTextToken);
        $journal = $this->upload('journal')->assertCreated()->json('id');
        $message = $this->upload('message', $otherLink->id)->assertCreated()->json('id');
        foreach ([$journal, $message] as $id) {
            $this->postJson("/api/v1/provider-conversations/{$link->id}/messages", [
                'client_id' => (string) Str::uuid(), 'audio_attachment_id' => $id,
            ])->assertUnprocessable();
        }
        $this->post('/api/v1/audio-attachments', ['client_id' => (string) Str::uuid(), 'purpose' => 'journal',
            'duration' => 301, 'audio' => UploadedFile::fake()->create('voice.m4a', 20481, 'audio/mp4')], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors(['duration', 'audio']);
        $this->post('/api/v1/audio-attachments', ['client_id' => (string) Str::uuid(), 'purpose' => 'journal',
            'duration' => 3, 'audio' => new UploadedFile($this->textFile(), 'voice.m4a', 'audio/mp4', null, true)], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('audio');
        $this->asProvider($provider)->postJson("/api/v1/provider/conversations/{$link->id}/messages", [
            'client_id' => (string) Str::uuid(), 'audio_attachment_id' => $message,
        ])->assertUnprocessable();
        $this->assertSame(0, ProviderMessage::count());
        $this->asProvider($provider)->getJson('/api/v1/provider/audio-attachments/'.$journal)->assertNotFound();
    }

    private function textFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'voice-test-');
        file_put_contents($path, 'This is not audio');
        $this->beforeApplicationDestroyed(fn () => @unlink($path));
        return $path;
    }

    public function test_browser_webm_is_converted_to_m4a_with_measured_duration(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $this->withToken($user->createToken('browser')->plainTextToken);
        $id = $this->post('/api/v1/audio-attachments', [
            'client_id' => (string) Str::uuid(), 'purpose' => 'journal', 'duration' => 1,
            'audio' => new UploadedFile(base_path('tests/Fixtures/voice.webm'), 'voice.webm', 'audio/webm', null, true),
        ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('media_type', 'audio/mp4')->assertJsonPath('duration', 2)->json('id');
        $audio = AudioAttachment::findOrFail($id);
        $content = Storage::disk('local')->get($audio->storage_key);
        $this->assertSame('ftyp', substr($content, 4, 4));
    }
}
