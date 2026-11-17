<?php

namespace Tests\Feature\Provider;

use App\Models\{ProviderMessage, QuizAttempt, User};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProviderOverviewTest extends ProviderDataTestCase
{
    public function test_overview_matches_lists_and_updates_after_read_revocation_and_unlink(): void
    {
        $provider = $this->provider();
        $other = $this->provider();
        $user = User::factory()->create();
        $link = $this->link($provider, $user, ['consent_quizzes' => true]);
        $this->link($other, User::factory()->create());
        $this->link($provider, User::factory()->create(), ['revoked_at' => now()]);
        $this->link($provider, User::factory()->create(), ['verified_at' => null]);
        ProviderMessage::create(['provider_client_link_id' => $link->id, 'sender' => 'patient', 'content' => 'Private', 'client_id' => (string) Str::uuid()]);
        ProviderMessage::create(['provider_client_link_id' => $link->id, 'sender' => 'provider', 'content' => 'Reply', 'client_id' => (string) Str::uuid()]);
        $attempt = QuizAttempt::create(['id' => (string) Str::uuid(), 'user_id' => $user->id, 'quiz_slug' => 'fixture', 'quiz_title' => 'Private title',
            'score' => 0, 'maximum_score' => 1, 'interpretation' => 'Fixture', 'answers' => [0], 'completed_at' => now(), 'validated_at' => now()]);
        $share = DB::table('quiz_shares')->insertGetId(['quiz_attempt_id' => $attempt->id, 'provider_id' => $provider->id, 'user_id' => $user->id,
            'link_id' => $link->id, 'consented_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $this->asProvider($provider);
        $summary = $this->getJson('/api/v1/provider/overview')->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.totals.connected_patients', 1)->assertJsonPath('data.totals.unread_messages', 1)->assertJsonPath('data.totals.shared_assessments', 1);
        $this->assertCount($summary->json('data.totals.connected_patients'), $this->getJson('/api/v1/provider/clients')->assertOk()->json());
        $this->getJson('/api/v1/provider/quiz-shares?per_page=1')->assertOk()->assertJsonPath('total', 1);
        $this->assertStringNotContainsString('Private', $summary->getContent());
        $this->assertStringNotContainsString('user_id', $summary->getContent());
        $this->postJson("/api/v1/provider/conversations/{$link->id}/read", ['through' => ProviderMessage::max('id')])->assertOk();
        $this->getJson('/api/v1/provider/overview')->assertOk()->assertJsonPath('data.totals.unread_messages', 0);
        DB::table('quiz_shares')->where('id', $share)->update(['revoked_at' => now()]);
        $this->getJson('/api/v1/provider/overview')->assertOk()->assertJsonPath('data.totals.shared_assessments', 0);
        $link->update(['revoked_at' => now()]);
        $this->getJson('/api/v1/provider/overview')->assertOk()->assertJsonPath('data.totals.connected_patients', 0);
    }

    public function test_overview_is_provider_only_and_requires_verification(): void
    {
        $this->getJson('/api/v1/provider/overview')->assertUnauthorized();
        $this->asProvider($this->provider(['is_verified' => false]));
        $this->getJson('/api/v1/provider/overview')->assertForbidden();
    }

    public function test_all_connected_patients_are_counted_and_deleted_patients_are_excluded(): void
    {
        $provider = $this->provider();
        foreach (User::factory()->count(22)->create() as $user) $this->link($provider, $user);
        $deleted = User::factory()->create();
        $this->link($provider, $deleted);
        $deleted->delete();
        $this->asProvider($provider);
        $this->getJson('/api/v1/provider/overview')->assertOk()->assertJsonPath('data.totals.connected_patients', 22)
            ->assertJsonPath('data.totals.shared_assessments', 0)->assertJsonPath('data.totals.unread_messages', 0);
    }

    public function test_filtering_an_ineligible_patient_preserves_roster_array_and_overview_parity(): void
    {
        $provider = $this->provider();
        $visible = User::factory()->create();
        $hidden = User::factory()->create();
        $this->link($provider, $visible, ['linked_at' => now()->subMinute()]);
        $this->link($provider, $hidden);
        $eligibility = new class extends \App\Services\AgeEligibility {
            public int $excluded;
            public function allows(User $user): bool { return $user->id !== $this->excluded; }
        };
        $eligibility->excluded = $hidden->id;
        $this->app->instance(\App\Services\AgeEligibility::class, $eligibility);
        $this->asProvider($provider);
        $this->getJson('/api/v1/provider/overview')->assertOk()->assertJsonPath('data.totals.connected_patients', 1);
        $roster = $this->getJson('/api/v1/provider/clients')->assertOk()->assertJsonPath('0.user_id', $visible->id)->json();
        $this->assertTrue(array_is_list($roster));
        $this->assertCount(1, $roster);
    }
}
