<?php

namespace Tests\Feature\Provider;

use App\Models\ProviderAccessLog;
use App\Models\ProviderNote;
use App\Models\User;

class ProviderNotesTest extends ProviderDataTestCase
{
    public function test_notes_require_consent(): void
    {
        $provider = $this->provider();
        $user = User::factory()->create();
        $this->link($provider, $user);
        $this->asProvider($provider)->getJson("/api/v1/provider/clients/{$user->id}/notes")->assertNotFound();
        $this->postJson("/api/v1/provider/clients/{$user->id}/notes", ['body' => 'Note'])->assertNotFound();
        $this->deleteJson("/api/v1/provider/clients/{$user->id}/notes/1")->assertNotFound();
        $this->assertDatabaseCount('provider_notes', 0);
        $this->assertDatabaseCount('provider_access_logs', 0);
    }

    public function test_provider_creates_own_note(): void
    {
        $provider = $this->provider();
        $user = User::factory()->create();
        $this->link($provider, $user, ['consent_notes' => true]);
        $this->asProvider($provider)->postJson("/api/v1/provider/clients/{$user->id}/notes",
            ['body' => 'Care note', 'provider_id' => 999, 'user_id' => 999])->assertCreated()
            ->assertJsonPath('note.provider_id', $provider->id)->assertJsonPath('note.user_id', $user->id);
    }

    public function test_provider_cannot_delete_other_providers_note(): void
    {
        $provider = $this->provider();
        $other = $this->provider();
        $user = User::factory()->create();
        $this->link($provider, $user, ['consent_notes' => true]);
        $note = ProviderNote::create(['provider_id' => $other->id, 'user_id' => $user->id, 'body' => 'Other note']);
        $this->asProvider($provider)->deleteJson("/api/v1/provider/clients/{$user->id}/notes/{$note->id}")->assertNotFound();
        $this->assertDatabaseHas('provider_notes', ['id' => $note->id]);
    }

    public function test_notes_are_scoped_to_provider(): void
    {
        $provider = $this->provider();
        $user = User::factory()->create();
        $this->link($provider, $user, ['consent_notes' => true]);
        $own = ProviderNote::create(['provider_id' => $provider->id, 'user_id' => $user->id, 'body' => 'Own']);
        ProviderNote::create(['provider_id' => $this->provider()->id, 'user_id' => $user->id, 'body' => 'Other']);
        ProviderNote::create(['provider_id' => $provider->id, 'user_id' => User::factory()->create()->id, 'body' => 'Other patient']);
        $this->asProvider($provider)->getJson("/api/v1/provider/clients/{$user->id}/notes")->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $own->id);
        $this->assertDatabaseHas('provider_access_logs', ['user_id' => $user->id, 'resource_type' => 'note', 'action' => 'list']);
    }

    public function test_note_creation_logged(): void
    {
        $provider = $this->provider();
        $user = User::factory()->create();
        $link = $this->link($provider, $user, ['consent_notes' => true]);
        $response = $this->asProvider($provider)->postJson("/api/v1/provider/clients/{$user->id}/notes", ['body' => 'Sensitive note'])->assertCreated();
        $this->assertDatabaseHas('provider_access_logs', ['provider_id' => $provider->id, 'user_id' => $user->id,
            'resource_type' => 'note', 'action' => 'create', 'resource_id' => (string) $response->json('note.id'), 'link_id' => $link->id]);
        $this->assertStringNotContainsString('Sensitive note', ProviderAccessLog::first()->toJson());
    }

    public function test_deletion_is_patient_scoped_logged_and_blocked_after_revocation(): void
    {
        $provider = $this->provider();
        $user = User::factory()->create();
        $link = $this->link($provider, $user, ['consent_notes' => true]);
        $note = ProviderNote::create(['provider_id' => $provider->id, 'user_id' => $user->id, 'body' => 'Own']);
        $other = ProviderNote::create(['provider_id' => $provider->id, 'user_id' => User::factory()->create()->id, 'body' => 'Other patient']);
        $this->asProvider($provider)->deleteJson("/api/v1/provider/clients/{$user->id}/notes/{$other->id}")->assertNotFound();
        $link->update(['revoked_at' => now()]);
        $this->deleteJson("/api/v1/provider/clients/{$user->id}/notes/{$note->id}")->assertNotFound();
        $link->update(['revoked_at' => null]);
        $this->deleteJson("/api/v1/provider/clients/{$user->id}/notes/{$note->id}")->assertOk();
        $this->assertDatabaseMissing('provider_notes', ['id' => $note->id]);
        $this->assertDatabaseHas('provider_access_logs', ['action' => 'delete', 'resource_type' => 'note', 'resource_id' => (string) $note->id]);
    }

    public function test_body_validation_and_pagination(): void
    {
        $provider = $this->provider();
        $user = User::factory()->create();
        $this->link($provider, $user, ['consent_notes' => true]);
        $this->asProvider($provider);
        foreach (['', str_repeat('x', 5001)] as $body) {
            $this->postJson("/api/v1/provider/clients/{$user->id}/notes", ['body' => $body])->assertUnprocessable();
        }
        $this->postJson("/api/v1/provider/clients/{$user->id}/notes", ['body' => str_repeat('x', 5000)])->assertCreated();
        $this->postJson("/api/v1/provider/clients/{$user->id}/notes", ['body' => 'Second'])->assertCreated();
        $this->getJson("/api/v1/provider/clients/{$user->id}/notes?per_page=1")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('total', 2);
    }
}
