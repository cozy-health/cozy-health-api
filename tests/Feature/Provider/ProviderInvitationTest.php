<?php

namespace Tests\Feature\Provider;

use App\Models\ProviderClientLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProviderInvitationTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_invitation_uses_only_new_table_and_registration_attaches_and_logs(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $this->postJson('/api/v1/provider/request', ['provider_email' => 'Invite@Example.test'])
            ->assertOk()->assertJsonPath('provider.provider_id', null);
        $link = ProviderClientLink::firstOrFail();
        $this->assertSame('invite@example.test', $link->provider_email);
        $this->assertDatabaseCount('provider_connections', 0);
        $this->postJson('/api/v1/provider/auth/register', ['name' => 'Invited Provider',
            'email' => 'INVITE@example.test', 'password' => 'test-password7', 'password_confirmation' => 'test-password7'])
            ->assertCreated();
        $link->refresh();
        $this->assertNotNull($link->provider_id);
        $this->assertNull($link->verified_at);
        $this->assertSame(0, ProviderClientLink::active()->count());
        $this->assertSame(['moods' => false, 'journals' => false, 'quizzes' => false, 'notes' => false], $link->consentFlags());
        $this->assertDatabaseHas('provider_access_logs', ['provider_id' => $link->provider_id,
            'user_id' => $user->id, 'action' => 'link_attached', 'resource_id' => (string) $link->id]);
    }

    public function test_reinvitation_reuses_revoked_link_and_requires_new_verification(): void
    {
        $user = User::factory()->create();
        $link = ProviderClientLink::create(['user_id' => $user->id, 'provider_email' => 'invite@example.test',
            'verified_at' => now()->subDay(), 'revoked_at' => now(), 'consent_moods' => true]);
        Sanctum::actingAs($user);
        $this->postJson('/api/v1/provider/request', ['provider_email' => 'invite@example.test'])
            ->assertOk()->assertJsonPath('provider.id', $link->id)->assertJsonPath('has_provider', false);
        $link->refresh();
        $this->assertNull($link->revoked_at);
        $this->assertNull($link->verified_at);
        $this->assertFalse($link->consent_moods);
        $this->assertDatabaseCount('provider_client_links', 1);
    }

    public function test_repeated_request_does_not_reset_verified_consent(): void
    {
        $user = User::factory()->create();
        $link = ProviderClientLink::create(['user_id' => $user->id, 'provider_email' => 'invite@example.test',
            'verified_at' => now(), 'consent_moods' => true]);
        Sanctum::actingAs($user);
        $this->postJson('/api/v1/provider/request', ['provider_email' => 'invite@example.test'])->assertOk()
            ->assertJsonPath('status', 'verified')->assertJsonPath('has_provider', true)->assertJsonMissingPath('data.development_code');
        $this->assertTrue($link->fresh()->consent_moods);
        $this->assertDatabaseCount('provider_client_links', 1);
    }

    public function test_cancel_current_invitation_preserves_other_verified_links(): void
    {
        $user = User::factory()->create();
        $existing = ProviderClientLink::create(['user_id' => $user->id, 'provider_email' => 'existing@example.test', 'verified_at' => now()]);
        $pending = ProviderClientLink::create(['user_id' => $user->id, 'provider_email' => 'pending@example.test']);
        Sanctum::actingAs($user);
        $this->deleteJson('/api/v1/provider')->assertOk();
        $this->assertNotNull($pending->fresh()->revoked_at);
        $this->assertNull($existing->fresh()->revoked_at);
        $this->getJson('/api/v1/provider')->assertOk()->assertJsonPath('provider.id', $existing->id);
    }
}
