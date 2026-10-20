<?php

namespace Tests\Feature\Provider;

use App\Models\ProviderClientLink;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

class PatientConsentTest extends ProviderDataTestCase
{
    public function test_patient_can_update_consent(): void
    {
        $user = User::factory()->create();
        $link = $this->link($this->provider(), $user);
        Sanctum::actingAs($user);
        foreach (['moods', 'journals', 'quizzes', 'notes'] as $type) {
            $this->patchJson("/api/v1/me/provider/{$link->id}/consent", ['consent_type' => $type, 'value' => true])
                ->assertOk()->assertJsonPath("link.consent_flags.$type", true);
            $this->assertTrue($link->fresh()->{'consent_'.$type});
        }
        $this->patchJson("/api/v1/me/provider/{$link->id}/consent", ['consent_type' => 'moods', 'value' => false])
            ->assertOk()->assertJsonPath('link.consent_flags.moods', false);
    }

    public function test_patient_cannot_update_other_patients_consent(): void
    {
        $link = $this->link($this->provider(), User::factory()->create());
        Sanctum::actingAs(User::factory()->create());
        $this->patchJson("/api/v1/me/provider/{$link->id}/consent", ['consent_type' => 'moods', 'value' => true])->assertNotFound();
        $this->deleteJson("/api/v1/me/provider/{$link->id}")->assertNotFound();
        $this->assertDatabaseCount('provider_access_logs', 0);
    }

    public function test_patient_can_revoke_link(): void
    {
        $user = User::factory()->create();
        $link = $this->link($this->provider(), $user);
        Sanctum::actingAs($user);
        $this->deleteJson("/api/v1/me/provider/{$link->id}")->assertOk();
        $this->assertNotNull($link->fresh()->revoked_at);
        $this->assertDatabaseHas('provider_access_logs', ['user_id' => $user->id, 'action' => 'revoked', 'link_id' => $link->id]);
    }

    public function test_patient_can_list_their_links(): void
    {
        $user = User::factory()->create();
        $this->link($this->provider(), $user);
        $this->link($this->provider(), $user, ['revoked_at' => now()]);
        $this->link($this->provider(), User::factory()->create());
        Sanctum::actingAs($user);
        $this->getJson('/api/v1/me/provider/links')->assertOk()->assertJsonCount(2, 'links')
            ->assertJsonPath('links.0.status', 'revoked')->assertJsonPath('links.1.provider.is_verified', true)
            ->assertJsonMissingPath('links.0.verification_code');
    }

    public function test_revoked_link_blocks_provider_access(): void
    {
        $provider = $this->provider();
        $user = User::factory()->create();
        $this->link($provider, $user, ['revoked_at' => now(), 'consent_moods' => true]);
        $this->asProvider($provider)->getJson("/api/v1/provider/clients/{$user->id}/moods")->assertNotFound();
    }

    public function test_consent_change_logged(): void
    {
        $user = User::factory()->create();
        $link = $this->link($this->provider(), $user);
        Sanctum::actingAs($user);
        $this->patchJson("/api/v1/me/provider/{$link->id}/consent", ['consent_type' => 'notes', 'value' => true])->assertOk();
        $this->assertDatabaseHas('provider_access_logs', ['provider_id' => $link->provider_id, 'user_id' => $user->id,
            'action' => 'consent_updated', 'resource_type' => 'consent', 'link_id' => $link->id, 'ip_address' => '127.0.0.1']);
    }

    public function test_invitation_consent_and_revocation_are_audited_without_registered_provider(): void
    {
        $user = User::factory()->create();
        $link = ProviderClientLink::create(['user_id' => $user->id, 'provider_email' => 'invited@example.test', 'verified_at' => now()]);
        Sanctum::actingAs($user);
        $this->patchJson("/api/v1/me/provider/{$link->id}/consent", ['consent_type' => 'moods', 'value' => true])->assertOk();
        $this->deleteJson("/api/v1/me/provider/{$link->id}")->assertOk();
        $this->assertDatabaseCount('provider_access_logs', 2);
        $this->assertDatabaseHas('provider_access_logs', ['provider_id' => null, 'link_id' => $link->id, 'action' => 'revoked']);
    }

    public function test_pending_revoked_and_invalid_consent_updates_are_rejected(): void
    {
        $user = User::factory()->create();
        $link = $this->link($this->provider(), $user, ['verified_at' => null]);
        Sanctum::actingAs($user);
        $this->patchJson("/api/v1/me/provider/{$link->id}/consent", ['consent_type' => 'moods', 'value' => true])->assertNotFound();
        $link->update(['verified_at' => now()]);
        $this->patchJson("/api/v1/me/provider/{$link->id}/consent", ['consent_type' => 'body', 'value' => true])->assertUnprocessable();
        $this->patchJson("/api/v1/me/provider/{$link->id}/consent", ['consent_type' => 'notes', 'value' => 'false'])->assertUnprocessable();
        $link->update(['revoked_at' => now()]);
        $this->patchJson("/api/v1/me/provider/{$link->id}/consent", ['consent_type' => 'notes', 'value' => true])->assertNotFound();
    }
}
