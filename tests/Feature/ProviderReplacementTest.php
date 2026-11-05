<?php

namespace Tests\Feature;

use App\Mail\ProviderInvitationMail;
use App\Models\Provider;
use App\Models\ProviderClientLink;
use App\Models\ProviderMessage;
use App\Models\SubscriptionStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProviderReplacementTest extends TestCase
{
    use RefreshDatabase;

    private User $patient;

    private ProviderClientLink $original;

    private Provider $replacement;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->travelTo(now()->startOfSecond());
        $this->patient = User::factory()->create();
        $old = Provider::create(['name' => 'Original', 'email' => 'original@example.test', 'is_active' => true]);
        $this->replacement = Provider::create(['name' => 'Replacement', 'email' => 'replacement@example.test', 'is_active' => true]);
        $this->original = ProviderClientLink::create(['user_id' => $this->patient->id, 'provider_id' => $old->id,
            'provider_email' => $old->email, 'verified_at' => now()->subMonth(), 'consent_moods' => true]);
        $this->login($this->patient);
    }

    private function login(User|Provider $actor): void
    {
        app('auth')->forgetGuards();
        $this->withToken($actor->createToken('device')->plainTextToken);
    }

    private function message(string $sender, $at): ProviderMessage
    {
        return ProviderMessage::create(['provider_client_link_id' => $this->original->id, 'sender' => $sender, 'client_id' => (string) Str::uuid(),
            'content' => 'Fixture message', 'created_at' => $at, 'updated_at' => $at]);
    }

    private function start(?string $id = null, ?string $email = null)
    {
        return $this->postJson('/api/v1/provider/replacements', ['original_link_id' => $this->original->id,
            'provider_email' => $email ?? $this->replacement->email, 'request_id' => $id ?? (string) Str::uuid()]);
    }

    public function test_clock_uses_first_unanswered_message_and_exact_elapsed_boundary(): void
    {
        $this->getJson('/api/v1/provider')->assertOk()->assertJsonPath('replacement.eligible', false);
        $first = $this->message('patient', now()->subHours(168)->addSecond());
        $this->message('patient', now()->subHour());
        $this->start()->assertConflict();
        $this->travel(1)->seconds();
        $this->getJson('/api/v1/provider')->assertOk()->assertJsonPath('replacement.eligible', true)
            ->assertJsonPath('replacement.qualifying_message_id', $first->id);
        $this->message('provider', now());
        $this->start()->assertConflict();
    }

    public function test_start_is_idempotent_and_keeps_current_provider_and_sharing(): void
    {
        $this->message('patient', now()->subDays(8));
        $id = (string) Str::uuid();
        $first = $this->start($id)->assertCreated();
        $this->start($id)->assertOk()->assertJsonPath('data.id', $first->json('data.id'));
        $this->start($id, 'other@example.test')->assertConflict();
        $this->start()->assertConflict();
        $this->assertNull($this->original->fresh()->revoked_at);
        $this->getJson('/api/v1/provider')->assertOk()->assertJsonPath('provider.link_id', $this->original->id)
            ->assertJsonPath('replacement.pending.provider_email', $this->replacement->email);
        $this->assertTrue($this->original->fresh()->consent_moods);
        Mail::assertQueued(ProviderInvitationMail::class, 1);
    }

    public function test_late_reply_does_not_cancel_choice_and_acceptance_revokes_old_access(): void
    {
        $this->message('patient', now()->subDays(8));
        $response = $this->start()->assertCreated();
        $this->message('provider', now());
        $this->login($this->replacement);
        $payload = ['code' => $response->json('data.invitation_code'), 'invitation_id' => $response->json('data.id'),
            'care_acknowledged' => true, 'revocation_acknowledged' => true,
            'consent_flags' => ['moods' => false, 'journals' => false, 'quizzes' => false, 'notes' => false]];
        $this->postJson('/api/v1/provider/invitations/connect', $payload)->assertOk();
        $this->assertNotNull($this->original->fresh()->revoked_at);
        $next = ProviderClientLink::findOrFail($response->json('data.id'));
        $this->assertNotNull($next->verified_at);
        $this->assertSame('accepted', $next->replacement_status);
        $this->assertSame(array_fill_keys(['moods', 'journals', 'quizzes', 'notes'], false), $next->consentFlags());
        $this->postJson('/api/v1/provider/invitations/connect', $payload)->assertUnprocessable();
    }

    public function test_cancel_and_expiry_preserve_original_and_do_not_allow_code_reuse(): void
    {
        $this->message('patient', now()->subDays(8));
        $first = $this->start()->assertCreated();
        $this->deleteJson('/api/v1/provider/replacements/'.$first->json('data.request_id'))->assertOk();
        $this->deleteJson('/api/v1/provider/replacements/'.$first->json('data.request_id'))->assertOk();
        $second = $this->start()->assertCreated();
        $this->travel(16)->minutes();
        $this->getJson('/api/v1/provider')->assertOk()->assertJsonPath('replacement.pending.status', 'expired');
        $this->assertNull($this->original->fresh()->revoked_at);
        $this->login($this->replacement);
        $this->postJson('/api/v1/provider/invitations/preview', ['code' => $second->json('data.invitation_code')])->assertUnprocessable();
    }

    public function test_original_revocation_invalidates_pending_and_foreign_users_cannot_cancel(): void
    {
        $this->message('patient', now()->subDays(8));
        $first = $this->start()->assertCreated();
        $this->login(User::factory()->create());
        $this->deleteJson('/api/v1/provider/replacements/'.$first->json('data.request_id'))->assertNotFound();
        $this->login($this->patient);
        $this->deleteJson('/api/v1/provider')->assertOk();
        $this->assertSame('cancelled', ProviderClientLink::findOrFail($first->json('data.id'))->replacement_status);
    }

    public function test_standard_and_expired_plans_cannot_start_or_complete_replacement(): void
    {
        config(['plan_access.enabled' => true]);
        $this->message('patient', now()->subDays(8));
        $this->start()->assertForbidden();
        $subscription = SubscriptionStatus::create(['id' => (string) Str::uuid(), 'user_id' => $this->patient->id, 'plan' => 'premium', 'status' => 'active', 'expires_at' => now()->addHour()]);
        $response = $this->start()->assertCreated();
        $subscription->update(['expires_at' => now()->subSecond()]);
        $this->login($this->replacement);
        $this->postJson('/api/v1/provider/invitations/connect', ['code' => $response->json('data.invitation_code'),
            'invitation_id' => $response->json('data.id'), 'care_acknowledged' => true, 'revocation_acknowledged' => true,
            'consent_flags' => array_fill_keys(['moods', 'journals', 'quizzes', 'notes'], false)])->assertForbidden();
        $this->assertNull($this->original->fresh()->revoked_at);
    }

    public function test_superseded_retry_and_cancel_cannot_affect_a_new_invitation(): void
    {
        $this->message('patient', now()->subDays(8));
        $request = (string) Str::uuid();
        $first = $this->start($request)->assertCreated();
        $this->deleteJson('/api/v1/provider/replacements/'.$request)->assertOk();
        $second = $this->start()->assertCreated();
        $this->start($request)->assertConflict();
        $this->deleteJson('/api/v1/provider/replacements/'.$request)->assertConflict();
        $this->assertNull(ProviderClientLink::findOrFail($second->json('data.id'))->revoked_at);
        $this->assertNotSame($request, $second->json('data.request_id'));
    }

    public function test_same_provider_inactive_provider_and_foreign_original_are_rejected(): void
    {
        $this->message('patient', now()->subDays(8));
        $this->start(null, $this->original->provider_email)->assertUnprocessable();
        $this->replacement->update(['is_active' => false]);
        $this->start()->assertUnprocessable();
        $this->login(User::factory()->create());
        $this->start()->assertNotFound();
    }

    public function test_invitation_queue_failure_rolls_back_replacement_and_retry_receipt(): void
    {
        $this->message('patient', now()->subDays(8));
        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('Synthetic queue outage'));
        $this->start()->assertServerError();
        $this->assertDatabaseCount('provider_client_links', 1);
        $this->assertDatabaseCount('provider_replacement_requests', 0);
        $this->assertNull($this->original->fresh()->revoked_at);
    }

    public function test_read_receipts_do_not_reset_clock_and_patient_message_after_reply_starts_new_wait(): void
    {
        $first = $this->message('patient', now()->subDays(8));
        $first->update(['read_at' => now()]);
        $this->getJson('/api/v1/provider')->assertOk()->assertJsonPath('replacement.eligible', true);
        $this->message('provider', now()->subHour());
        $next = $this->message('patient', now()->subMinutes(30));
        $this->getJson('/api/v1/provider')->assertOk()->assertJsonPath('replacement.eligible', false)
            ->assertJsonPath('replacement.qualifying_message_id', $next->id);
    }

    public function test_reusing_an_older_provider_invitation_still_blocks_a_second_pending_choice(): void
    {
        $this->message('patient', now()->subDays(8));
        $this->start()->assertCreated();
        $this->travel(16)->minutes();
        $other = $this->start(null, 'other@example.test')->assertCreated();
        $this->travel(16)->minutes();
        $again = $this->start()->assertCreated();
        $this->assertLessThan($other->json('data.id'), $again->json('data.id'));
        $this->getJson('/api/v1/provider')->assertOk()->assertJsonPath('replacement.eligible', false)
            ->assertJsonPath('replacement.pending.id', $again->json('data.id'));
        $this->start(null, 'third@example.test')->assertConflict();
    }
}
