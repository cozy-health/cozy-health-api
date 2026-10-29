<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\PlanAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PlanAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void { parent::setUp(); config(['plan_access.enabled' => true]); }
    private function account(?string $plan): User
    {
        $user = User::factory()->create();
        if ($plan) DB::table('subscription_statuses')->insert(['id' => (string) Str::uuid(), 'user_id' => $user->id, 'plan' => $plan, 'status' => 'active', 'started_at' => now()->subDay(), 'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
        Sanctum::actingAs($user);
        return $user;
    }
    public function test_standard_student_and_premium_have_exact_permissions_and_no_free_fallback(): void
    {
        foreach ([null, 'free', 'standard', 'student', 'premium'] as $plan) {
            $this->account($plan);
            $actual = $this->getJson('/api/v1/me/plan-access')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json('data');
            $expected = ['emergency', 'existing_records'];
            if (in_array($plan, ['standard', 'student', 'premium'])) $expected = [...$expected, ...PlanAccess::STANDARD];
            if (in_array($plan, ['student', 'premium'])) $expected = [...$expected, ...PlanAccess::PREMIUM];
            $this->assertSame($expected, $actual['features']);
            $this->assertSame($plan === 'free' ? null : $plan, $actual['plan']);
        }
    }
    public function test_standard_cannot_bypass_premium_writes_and_unsubscribed_cannot_create_basic_records(): void
    {
        $this->account('standard');
        foreach (['audio-attachments', 'provider/request', 'provider/verify', 'provider-conversations/1/messages',
            'provider-conversations/1/journal-shares', 'quizzes/1/start', 'quizzes/1/submit', 'quizzes/submit-offline',
            'quiz-attempts', 'wellness/challenges/1/enroll', 'wellness/challenges/1/complete', 'community/posts',
            'community/posts/00000000-0000-4000-8000-000000000001/comments', 'community/groups/1/join'] as $path) {
            $this->travel(1)->minutes();
            $this->postJson('/api/v1/'.$path, ['plan' => 'premium', 'is_premium' => true])->assertForbidden()->assertJsonPath('code', 'plan_required');
        }
        $this->putJson('/api/v1/community/posts/00000000-0000-4000-8000-000000000001/reaction', [])->assertForbidden()->assertJsonPath('code', 'plan_required');
        $this->postJson('/api/v1/journal-entries', ['type' => 'free', 'voice_url' => 'https://example.test/voice'])->assertForbidden()->assertJsonPath('feature', 'voice');
        $this->account(null);
        $this->postJson('/api/v1/mood-entries', [])->assertForbidden()->assertJsonPath('feature', 'mood_checkin');
        $this->postJson('/api/v1/journal-entries', [])->assertForbidden()->assertJsonPath('feature', 'written_journal');
        $this->assertDatabaseCount('journal_entries', 0);
        $this->assertDatabaseCount('quiz_attempts', 0);
    }
    public function test_basic_plan_can_save_written_journal_and_expiry_preserves_read_delete_and_emergency_access(): void
    {
        $user = $this->account('standard');
        $id = (string) Str::uuid();
        $this->postJson('/api/v1/journal-entries', ['id' => $id, 'type' => 'free', 'body' => 'Fixture entry', 'is_draft' => false, 'client_created_at' => now()->toIso8601String()])->assertOk();
        \App\Models\SafetyPlan::create(['id' => (string) Str::uuid(), 'user_id' => $user->id]);
        $this->travel(2)->days();
        $this->getJson('/api/v1/journal-entries/'.$id)->assertOk()->assertJsonPath('data.body', 'Fixture entry');
        $this->getJson('/api/v1/crisis/resources')->assertOk();
        $this->getJson('/api/v1/safety-plan')->assertOk();
        $this->getJson('/api/v1/quiz-attempts')->assertOk();
        $this->getJson('/api/v1/provider-conversations')->assertOk();
        $this->deleteJson('/api/v1/journal-entries/'.$id)->assertOk();
        $this->postJson('/api/v1/journal-entries', [])->assertForbidden()->assertJsonPath('code', 'plan_required');
        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/v1/journal-entries/'.$id)->assertNotFound();
    }
    public function test_sync_blocks_plan_bypasses_per_operation_without_rejecting_emergency_updates(): void
    {
        $this->account('standard');
        $operations = [];
        foreach (['journal_entry', 'quiz_attempt', 'subscription_status'] as $type) $operations[] = ['type' => $type, 'action' => 'upsert', 'data' => ['id' => (string) Str::uuid(), 'type' => 'free', 'voice_url' => 'https://example.test/voice', 'plan' => 'premium']];
        $operations[] = ['type' => 'safety_plan', 'action' => 'upsert', 'data' => ['id' => (string) Str::uuid()]];
        $this->postJson('/api/v1/sync/batch', ['operations' => $operations])->assertOk()
            ->assertJsonPath('data.results.0.error_code', 'plan_required')->assertJsonPath('data.results.1.error_code', 'plan_required')->assertJsonPath('data.results.2.error_code', 'access_denied')->assertJsonPath('data.results.3.success', true);
        $this->assertDatabaseCount('journal_entries', 0);
        $this->assertDatabaseCount('quiz_attempts', 0);
    }
    public function test_student_and_premium_reach_premium_action_validation_instead_of_plan_denial(): void
    {
        foreach (['student', 'premium'] as $plan) {
            $this->account($plan);
            $this->postJson('/api/v1/audio-attachments', [])->assertUnprocessable();
            $this->postJson('/api/v1/provider/request', [])->assertUnprocessable();
        }
    }

    public function test_development_grant_switches_actual_permissions_and_revoke_restores_real_plan(): void
    {
        config(['development_access.enabled' => true, 'app.url' => 'https://api-dev.cozyhealth.org',
            'development_access.railway_project_id' => \App\Services\CommunityAvailability::DEVELOPMENT_PROJECT,
            'development_access.railway_environment_id' => \App\Services\CommunityAvailability::DEVELOPMENT_ENVIRONMENT]);
        $user = $this->account('standard');
        $id = (string) Str::uuid();
        DB::table('development_access_grants')->insert(['id' => $id, 'user_id' => $user->id, 'plan' => 'student', 'reason' => encrypt('Synthetic plan test'), 'starts_at' => now(), 'expires_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now()]);
        $this->assertContains('voice', $this->getJson('/api/v1/me/plan-access')->assertOk()->assertJsonPath('data.source', 'development_test')->json('data.features'));
        $this->postJson('/api/v1/audio-attachments', [])->assertUnprocessable();
        DB::table('development_access_grants')->where('id', $id)->update(['revoked_at' => now()]);
        $this->getJson('/api/v1/me/plan-access')->assertOk()->assertJsonPath('data.plan', 'standard');
        $this->postJson('/api/v1/audio-attachments', [])->assertForbidden()->assertJsonPath('code', 'plan_required');
        $this->travel(2)->days();
        $this->getJson('/api/v1/me/plan-access')->assertOk()->assertJsonPath('data.plan', null);
    }

    public function test_encoded_route_names_cannot_bypass_plan_permissions(): void
    {
        $this->account('standard');
        $this->postJson('/api/v1/%61udio-attachments', [])->assertForbidden()->assertJsonPath('code', 'plan_required');
        $this->postJson('/api/v1/prov%69der/request', [])->assertForbidden()->assertJsonPath('code', 'plan_required');
        $this->account(null);
        $this->postJson('/api/v1/%6dood-entries', [])->assertForbidden()->assertJsonPath('code', 'plan_required');
    }

    public function test_standard_cannot_open_derived_insights_but_keeps_home_history_and_emergency(): void
    {
        $user = $this->account('standard');
        \App\Models\MoodEntry::create(['id' => (string) Str::uuid(), 'user_id' => $user->id, 'mood' => 'good', 'intensity' => 6, 'client_created_at' => now()]);
        foreach (['insights/weekly', 'insights/triggers', 'insights/sleep-mood'] as $path) {
            $this->getJson('/api/v1/'.$path)->assertForbidden()->assertJsonPath('feature', 'trends_dashboard')
                ->assertJsonPath('allowed_plans', ['student', 'premium']);
        }
        $this->getJson('/api/v1/dashboard')->assertOk()->assertJsonPath('data.weekly_moods', [])
            ->assertJsonCount(1, 'data.recent_entries')->assertJsonPath('data.today_mood.mood', 'good');
        $this->getJson('/api/v1/mood-entries')->assertOk();
        $this->getJson('/api/v1/crisis/resources')->assertOk();
    }

    public function test_student_and_premium_can_open_insights_until_their_plan_expires(): void
    {
        foreach (['student', 'premium'] as $plan) {
            $user = $this->account($plan);
            \App\Models\MoodEntry::create(['id' => (string) Str::uuid(), 'user_id' => $user->id, 'mood' => 'good', 'intensity' => 6, 'client_created_at' => now()]);
            $this->assertContains('trends_dashboard', $this->getJson('/api/v1/me/plan-access')->assertOk()->json('data.features'));
            foreach (['insights/weekly', 'insights/triggers', 'insights/sleep-mood'] as $path) {
                $this->getJson('/api/v1/'.$path)->assertOk();
            }
            $this->getJson('/api/v1/dashboard')->assertOk()->assertJsonCount(1, 'data.weekly_moods');
            $this->travel(2)->days();
            $this->getJson('/api/v1/insights/weekly')->assertForbidden()->assertJsonPath('feature', 'trends_dashboard');
            $this->getJson('/api/v1/dashboard')->assertOk()->assertJsonPath('data.weekly_moods', [])
                ->assertJsonCount(1, 'data.recent_entries');
            $this->travelBack();
        }
    }
}
