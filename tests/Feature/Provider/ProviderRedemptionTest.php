<?php

namespace Tests\Feature\Provider;

use App\Mail\ProviderInvitationMail;
use App\Models\Provider;
use App\Models\ProviderClientLink;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;

class ProviderRedemptionTest extends ProviderDataTestCase
{
    private array $flags = ['moods' => true, 'journals' => false, 'quizzes' => false, 'notes' => false];

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Cache::flush();
    }

    private function invite(User $user, string $email): array
    {
        Sanctum::actingAs($user);
        $response = $this->postJson('/api/v1/provider/request', ['provider_email' => $email,
            'verification_actor' => 'provider', 'consent_flags' => $this->flags])->assertOk();
        $mail = Mail::queued(ProviderInvitationMail::class)->last();
        $response->assertJsonPath('provider.invitation_code', $mail->code)
            ->assertJsonMissingPath('provider.invitation_code_secret');

        return [$response, ProviderClientLink::findOrFail($response->json('provider.id')), $mail->code];
    }

    private function providerLogin(Provider $provider): void
    {
        Sanctum::actingAs($provider, ['*'], 'provider');
    }

    private function confirmation(ProviderClientLink $link, string $code): array
    {
        return ['invitation_id' => $link->id, 'code' => $code, 'consent_flags' => $this->flags,
            'care_acknowledged' => true, 'revocation_acknowledged' => true];
    }

    public function test_corrected_invitation_cancels_only_the_owned_pending_link(): void
    {
        $patient = User::factory()->create();
        $oldProvider = $this->provider();
        [, $old, $oldCode] = $this->invite($patient, $oldProvider->email);
        $this->deleteJson('/api/v1/provider/invitations/'.$old->id)->assertOk();
        $old->refresh();
        $this->assertNotNull($old->revoked_at);
        $this->assertNull($old->verification_code);
        $this->assertNull($old->invitation_code_secret);
        $this->deleteJson('/api/v1/provider/invitations/'.$old->id)->assertOk();
        $newProvider = $this->provider();
        [, $new, $newCode] = $this->invite($patient, $newProvider->email);
        $this->assertSame($this->flags, $new->consentFlags());
        $this->providerLogin($oldProvider);
        $this->postJson('/api/v1/provider/invitations/preview', ['code' => $oldCode])->assertUnprocessable();
        $this->postJson('/api/v1/provider/invitations/preview', ['code' => $newCode])->assertUnprocessable();
        $this->providerLogin($newProvider);
        $this->postJson('/api/v1/provider/invitations/connect', $this->confirmation($new, $newCode))->assertOk();
    }

    public function test_correction_cannot_cancel_another_patient_or_an_accepted_connection(): void
    {
        $patient = User::factory()->create();
        [, $link] = $this->invite($patient, $this->provider()->email);
        Sanctum::actingAs(User::factory()->create());
        $this->deleteJson('/api/v1/provider/invitations/'.$link->id)->assertNotFound();
        Sanctum::actingAs($patient);
        $link->update(['verified_at' => now()]);
        $this->deleteJson('/api/v1/provider/invitations/'.$link->id)->assertStatus(409);
        $this->assertNull($link->fresh()->revoked_at);
    }

    public function test_shareable_code_is_owner_only_encrypted_and_expires(): void
    {
        $provider = $this->provider();
        $patient = User::factory()->create();
        [, $link, $code] = $this->invite($patient, $provider->email);
        $this->assertSame($code, $link->invitation_code_secret);
        $stored = DB::table('provider_client_links')->where('id', $link->id)->value('invitation_code_secret');
        $this->assertStringNotContainsString($code, $stored);
        $this->assertArrayNotHasKey('invitation_code_secret', $link->toArray());
        $this->getJson('/api/v1/provider')->assertOk()->assertJsonPath('provider.invitation_code', $code);
        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/v1/provider')->assertJsonPath('provider', null);
        $this->providerLogin($provider);
        $this->getJson('/api/v1/provider/clients')->assertExactJson([]);
        $this->getJson('/api/v1/provider/conversations')->assertExactJson([]);
        $this->getJson('/api/v1/provider/clients/'.$patient->id)->assertNotFound();
        $this->getJson('/api/v1/provider/conversations/'.$link->id.'/messages')->assertNotFound();
        $this->postJson('/api/v1/provider/invitations/preview', ['code' => $code])->assertOk()
            ->assertJsonMissingPath('data.invitation_code_secret')->assertJsonMissingPath('data.invitation_code');
        Sanctum::actingAs($patient);
        $this->travel(16)->minutes();
        $this->getJson('/api/v1/provider')->assertJsonPath('provider.invitation_code', null);
        $this->providerLogin($provider);
        $this->postJson('/api/v1/provider/invitations/preview', ['code' => $code])->assertUnprocessable();
    }

    public function test_revocation_clears_shareable_code_through_both_patient_routes(): void
    {
        foreach (['/api/v1/provider', '/api/v1/me/provider/'] as $path) {
            $patient = User::factory()->create();
            [, $link] = $this->invite($patient, $this->provider()->email);
            $this->deleteJson($path === '/api/v1/provider' ? $path : $path.$link->id)->assertOk();
            $this->assertNull($link->fresh()->invitation_code_secret);
            $this->getJson('/api/v1/provider')->assertJsonPath('provider', null);
        }
    }

    public function test_registration_without_patients_creates_an_unverified_account_and_empty_roster(): void
    {
        $result = $this->postJson('/api/v1/provider/auth/register', ['name' => 'New Provider', 'email' => 'new@example.test',
            'password' => 'NewProviderPassword123', 'password_confirmation' => 'NewProviderPassword123'])->assertCreated();
        $this->assertFalse($result->json('data.provider.is_verified'));
        $this->providerLogin(Provider::findOrFail($result->json('data.provider.id')));
        $this->getJson('/api/v1/provider/clients')->assertOk()->assertExactJson([]);
    }

    public function test_email_code_is_hidden_and_preview_does_not_connect_or_expose_wellness_data(): void
    {
        $provider = $this->provider();
        [$response, $link, $code] = $this->invite(User::factory()->create(), $provider->email);
        $response->assertJsonMissingPath('data.development_code')->assertJsonMissingPath('provider.verification_code')
            ->assertJsonMissingPath('provider.invitation_code_lookup')->assertJsonPath('provider.consent_flags.moods', true);
        Mail::assertQueued(ProviderInvitationMail::class, fn ($mail) => $mail->hasTo($provider->email) && $mail->shouldSend());
        $this->assertTrue(Hash::check($code, $link->verification_code));
        $this->assertNotSame($code, $link->invitation_code_lookup);
        $this->postJson('/api/v1/provider/verify', ['code' => $code])->assertUnprocessable();
        $this->providerLogin($provider);
        $this->postJson('/api/v1/provider/invitations/preview', ['code' => $code])->assertOk()
            ->assertJsonPath('data.invitation_id', $link->id)->assertJsonMissingPath('data.user_id')
            ->assertJsonMissingPath('data.moods')->assertJsonMissingPath('data.email');
        $this->getJson('/api/v1/provider/clients')->assertExactJson([]);
        $this->assertNull($link->fresh()->verified_at);
    }

    public function test_connect_preserves_patient_permissions_consumes_code_and_audits_acknowledgement(): void
    {
        $provider = $this->provider();
        $user = User::factory()->create();
        [, $link, $code] = $this->invite($user, $provider->email);
        $this->providerLogin($provider);
        $body = $this->confirmation($link, $code);
        $this->postJson('/api/v1/provider/invitations/connect', [...$body, 'care_acknowledged' => false])->assertUnprocessable();
        $this->postJson('/api/v1/provider/invitations/connect', $body)->assertOk()->assertJsonPath('data.user_id', $user->id);
        $this->assertSame($this->flags, $link->refresh()->consentFlags());
        $this->assertNotNull($link->provider_acknowledged_at);
        $this->assertNull($link->verification_code);
        $this->assertNull($link->invitation_code_secret);
        $this->assertNull($link->invitation_code_lookup);
        $this->getJson('/api/v1/provider/clients')->assertOk()->assertJsonPath('0.user_id', $user->id);
        $this->getJson('/api/v1/provider/clients/'.$user->id.'/journals')->assertNotFound();
        $this->postJson('/api/v1/provider/invitations/connect', $body)->assertUnprocessable();
        $this->assertDatabaseCount('provider_client_links', 1);
        $this->assertDatabaseHas('provider_access_logs', ['link_id' => $link->id, 'action' => 'invitation_connected']);
    }

    public function test_invitation_before_registration_can_be_redeemed_by_matching_account(): void
    {
        [, $link, $code] = $this->invite(User::factory()->create(), 'future@example.test');
        $this->assertNull($link->provider_id);
        $registration = $this->postJson('/api/v1/provider/auth/register', ['name' => 'Future Provider', 'email' => 'FUTURE@example.test',
            'password' => 'NewProviderPassword123', 'password_confirmation' => 'NewProviderPassword123'])->assertCreated();
        $this->providerLogin(Provider::findOrFail($registration->json('data.provider.id')));
        $this->postJson('/api/v1/provider/invitations/connect', $this->confirmation($link, $code))->assertOk();
    }

    public function test_wrong_account_expired_and_cancelled_codes_cannot_be_previewed(): void
    {
        $provider = $this->provider();
        [, $link, $code] = $this->invite(User::factory()->create(), $provider->email);
        $this->providerLogin($this->provider());
        $this->postJson('/api/v1/provider/invitations/preview', ['code' => $code])->assertUnprocessable();
        $this->providerLogin($provider);
        $link->update(['verification_expires_at' => now()->subSecond()]);
        $this->postJson('/api/v1/provider/invitations/preview', ['code' => $code])->assertUnprocessable();
        $link->update(['verification_expires_at' => now()->addMinutes(15), 'revoked_at' => now()]);
        $this->postJson('/api/v1/provider/invitations/preview', ['code' => $code])->assertUnprocessable();
        $this->assertFalse(Mail::queued(ProviderInvitationMail::class)->last()->shouldSend());
    }

    public function test_resend_invalidates_old_code_and_is_throttled(): void
    {
        $provider = $this->provider();
        $user = User::factory()->create();
        [, $link, $oldCode] = $this->invite($user, $provider->email);
        $this->postJson('/api/v1/provider/request', ['provider_email' => $provider->email, 'verification_actor' => 'provider'])->assertStatus(429);
        $this->travel(61)->seconds();
        [, , $newCode] = $this->invite($user, $provider->email);
        $this->getJson('/api/v1/provider')->assertJsonPath('provider.invitation_code', $newCode);
        $this->assertFalse(Mail::queued(ProviderInvitationMail::class)->first()->shouldSend());
        $this->providerLogin($provider);
        $this->postJson('/api/v1/provider/invitations/preview', ['code' => $oldCode])->assertUnprocessable();
        $this->postJson('/api/v1/provider/invitations/preview', ['code' => $newCode])->assertOk();
        $this->assertDatabaseCount('provider_client_links', 1);
    }

    public function test_consent_change_after_preview_requires_a_new_review(): void
    {
        $provider = $this->provider();
        [, $link, $code] = $this->invite(User::factory()->create(), $provider->email);
        $this->providerLogin($provider);
        $this->postJson('/api/v1/provider/invitations/preview', ['code' => $code])->assertOk();
        $link->update(['consent_moods' => false]);
        $this->postJson('/api/v1/provider/invitations/connect', $this->confirmation($link, $code))->assertStatus(409);
        $this->assertNull($link->fresh()->verified_at);
    }

    public function test_ambiguous_codes_are_rejected_without_selecting_a_patient(): void
    {
        $provider = $this->provider();
        [, $link, $code] = $this->invite(User::factory()->create(), $provider->email);
        ProviderClientLink::create([...$link->getAttributes(), 'id' => null, 'user_id' => User::factory()->create()->id]);
        $this->providerLogin($provider);
        $this->postJson('/api/v1/provider/invitations/preview', ['code' => $code])->assertUnprocessable();
        $this->assertSame(0, ProviderClientLink::active()->count());
    }

    public function test_all_private_connection_and_later_patient_revocation(): void
    {
        $this->flags = array_fill_keys(array_keys($this->flags), false);
        $provider = $this->provider();
        $user = User::factory()->create();
        [, $link, $code] = $this->invite($user, $provider->email);
        $this->providerLogin($provider);
        $this->postJson('/api/v1/provider/invitations/connect', $this->confirmation($link, $code))->assertOk();
        $this->getJson('/api/v1/provider/clients/'.$user->id.'/moods')->assertNotFound();
        Sanctum::actingAs($user);
        $this->deleteJson('/api/v1/provider')->assertOk();
        $this->providerLogin($provider);
        $this->getJson('/api/v1/provider/clients')->assertExactJson([]);
        $this->getJson('/api/v1/provider/clients/'.$user->id)->assertNotFound();
    }

    public function test_cancellation_after_preview_prevents_confirmation(): void
    {
        $provider = $this->provider();
        [, $link, $code] = $this->invite(User::factory()->create(), $provider->email);
        $this->providerLogin($provider);
        $this->postJson('/api/v1/provider/invitations/preview', ['code' => $code])->assertOk();
        $link->update(['revoked_at' => now()]);
        $this->postJson('/api/v1/provider/invitations/connect', $this->confirmation($link, $code))->assertUnprocessable();
        $this->assertNull($link->fresh()->verified_at);
    }

    public function test_queue_failure_does_not_claim_delivery_and_can_be_retried(): void
    {
        $provider = $this->provider();
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $mailFake = Mail::getFacadeRoot();
        Mail::shouldReceive('to')->once()->with($provider->email)->andReturn(new class
        {
            public function queue($mail): void
            {
                throw new \RuntimeException('Private transport error');
            }
        });
        $body = ['provider_email' => $provider->email, 'verification_actor' => 'provider', 'consent_flags' => $this->flags];
        $this->postJson('/api/v1/provider/request', $body)->assertStatus(503)->assertJsonMissing(['Private transport error']);
        $this->assertNull(ProviderClientLink::firstOrFail()->invitation_sent_at);
        Mail::swap($mailFake);
        $this->postJson('/api/v1/provider/request', $body)->assertOk();
        Mail::assertQueued(ProviderInvitationMail::class);
        $this->assertDatabaseCount('provider_client_links', 1);
    }
}
