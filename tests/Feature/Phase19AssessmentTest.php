<?php
namespace Tests\Feature;

use App\Models\{Provider, ProviderClientLink, Quiz, QuizAttempt, QuizDefinition, User};
use App\Services\{AssessmentService, QuizSharingService};
use App\Services\Provider\ProviderReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class Phase19AssessmentTest extends TestCase
{
    use RefreshDatabase;
    protected function setUp(): void { parent::setUp(); \Illuminate\Support\Facades\Storage::fake('local'); }
    private function login(?User $user = null): User
    {
        $user ??= User::factory()->create(); app('auth')->forgetGuards();
        $this->withToken($user->createToken('device')->plainTextToken); return $user;
    }
    private function version(string $slug = 'fixture'): array
    {
        $quiz = Quiz::create(['slug' => $slug, 'title' => 'Fixture', 'type' => 'fixture', 'is_active' => true, 'approved_at' => now(),
            'disclaimer' => 'Not a substitute for professional care.', 'scoring_rules' => ['bands' => [['max' => 6, 'label' => 'Fixture band']]]]);
        $answers = [];
        for ($i = 0; $i < 2; $i++) {
            $question = $quiz->questions()->create(['question' => 'Fixture '.$i, 'sort_order' => $i]);
            $question->options()->create(['label' => 'Low', 'score' => 0, 'sort_order' => 0]);
            $answers[] = $question->options()->create(['label' => 'High', 'score' => 3, 'sort_order' => 1])->id;
        }
        return [app(AssessmentService::class)->publish($quiz), $answers];
    }
    private function body(QuizDefinition $version, array $answers): array
    {
        return ['id' => (string) Str::uuid(), 'definition_version' => $version->id, 'answers' => $answers,
            'completed_at' => now()->subDays(2)->toIso8601String(), 'disclaimer_accepted' => true, 'score' => 999, 'interpretation' => 'Forged'];
    }
    public function test_username_collisions_are_actionable_in_profile_and_sync_and_resolution_is_exact(): void
    {
        $friend = User::factory()->create();
        // Represents a pre-migration handle whose display value is preserved.
        DB::table('user_profiles')->insert(['id' => (string) Str::uuid(), 'user_id' => $friend->id,
            'name' => $friend->name, 'email' => $friend->email, 'username' => ' Friendly_User ', 'username_key' => 'friendly_user']);
        $this->login();
        $this->patchJson('/api/v1/user/profile', ['username' => 'FRIENDLY_USER'])->assertUnprocessable()->assertJsonValidationErrors('username');
        $profileId = \App\Models\UserProfile::firstOrFail()->id;
        $this->postJson('/api/v1/sync/batch', ['operations' => [['type' => 'user_profile', 'action' => 'upsert',
            'data' => ['id' => $profileId, 'username' => 'friendly_user']]]])
            ->assertOk()->assertJsonPath('data.results.0.success', false)->assertJsonPath('data.results.0.error_code', 'invalid_payload');
        $this->getJson('/api/v1/quizzes/recipients/resolve?username=FRIENDLY_USER')->assertOk()->assertJsonPath('data.id', $friend->id);
        $this->getJson('/api/v1/quizzes/recipients/resolve?username=Friendly')->assertNotFound();
        $this->patchJson('/api/v1/user/profile', ['username' => 'new_owner'])->assertOk()->assertJsonMissingPath('data.username_key');
    }
    public function test_offline_and_sync_return_canonical_scores_and_keep_snapshots(): void
    {
        $owner = $this->login(); [$version, $answers] = $this->version(); $body = $this->body($version, $answers);
        DB::table('quiz_options')->update(['score' => 99]);
        $this->postJson('/api/v1/quizzes/submit-offline', $body)->assertOk()->assertJsonPath('data.score', 6)->assertJsonPath('data.maximum_score', 6);
        $this->postJson('/api/v1/quizzes/submit-offline', $body)->assertOk();
        $this->assertDatabaseCount('quiz_attempts', 1);
        $next = [...$body, 'id' => (string) Str::uuid()];
        $this->postJson('/api/v1/sync/batch', ['operations' => [['type' => 'quiz_attempt', 'action' => 'upsert', 'data' => $next]]])
            ->assertOk()->assertJsonPath('data.results.0.success', true)->assertJsonPath('data.results.0.record.score', 6);
        $this->postJson('/api/v1/quizzes/submit-offline', [...$body, 'answers' => array_reverse($answers)])->assertConflict();
        $this->login(); $this->getJson('/api/v1/quizzes/attempts/'.$body['id'])->assertNotFound();
    }
    public function test_sync_cannot_bypass_validation_or_restore_deleted_attempts(): void
    {
        $this->login(); [$version, $answers] = $this->version(); $body = $this->body($version, $answers);
        $this->postJson('/api/v1/quizzes/submit-offline', $body)->assertOk();
        $this->deleteJson('/api/v1/quiz-attempts/'.$body['id'])->assertOk();
        $this->postJson('/api/v1/sync/batch', ['operations' => [['type' => 'quiz_attempt', 'action' => 'upsert', 'data' => $body]]])->assertJsonPath('data.results.0.error_code', 'gone');
        $this->getJson('/api/v1/quiz-attempts?include_deleted=1')->assertJsonPath('data.data.0.is_deleted', true);
        $this->postJson('/api/v1/sync/batch', ['operations' => [['type' => 'quiz_attempt', 'action' => 'upsert', 'data' => ['id' => (string) Str::uuid(), 'score' => 12]]]])->assertJsonPath('data.results.0.error_code', 'invalid_payload');
    }
    public function test_friend_grants_are_selected_and_revocable_without_answer_leaks(): void
    {
        $owner = $this->login(); [$version, $answers] = $this->version(); $body = $this->body($version, $answers);
        $this->postJson('/api/v1/quizzes/submit-offline', $body)->assertOk();
        $friend = User::factory()->create();
        $share = $this->postJson('/api/v1/quizzes/attempts/'.$body['id'].'/friends', ['recipient_id' => $friend->id, 'consent' => true])->assertOk()->json('data.id');
        $this->login($friend);
        $this->getJson('/api/v1/quizzes/shared/'.$share)->assertOk()->assertJsonMissingPath('data.answers')->assertJsonMissingPath('data.result_snapshot')->assertJsonPath('data.score', 6);
        $this->login(); $this->getJson('/api/v1/quizzes/shared/'.$share)->assertNotFound();
        $this->login($owner); $this->deleteJson('/api/v1/quizzes/attempts/'.$body['id'].'/grants/friend/'.$share)->assertOk();
        $this->login($friend); $this->getJson('/api/v1/quizzes/shared/'.$share)->assertNotFound();
    }
    public function test_invitation_requires_onboarding_is_single_use_and_can_expire(): void
    {
        $owner = $this->login(); [$version, $answers] = $this->version(); $body = $this->body($version, $answers);
        $this->postJson('/api/v1/quizzes/submit-offline', $body)->assertOk();
        $url = $this->postJson('/api/v1/quizzes/attempts/'.$body['id'].'/invitations', ['consent' => true])->assertOk()->json('data.url');
        $token = basename($url); $this->assertDatabaseMissing('quiz_invitations', ['token_hash' => $token]);
        $friend = $this->login();
        $this->postJson('/api/v1/quizzes/invitations/claim', ['token' => $token, 'consent' => true])->assertConflict();
        $friend->profile()->create(['id' => (string) Str::uuid(), 'name' => $friend->name, 'email' => $friend->email, 'onboarding_completed_at' => now()]);
        $this->postJson('/api/v1/quizzes/invitations/claim', ['token' => $token, 'consent' => true])->assertOk();
        $this->postJson('/api/v1/quizzes/invitations/claim', ['token' => $token, 'consent' => true])->assertOk();
        $this->login(); $this->postJson('/api/v1/quizzes/invitations/claim', ['token' => $token, 'consent' => true])->assertStatus(410);
        $this->login($friend); $this->travel(8)->days();
        $this->postJson('/api/v1/quizzes/invitations/claim', ['token' => $token, 'consent' => true])->assertOk();
    }
    public function test_provider_reports_stop_working_when_an_attempt_share_is_revoked(): void
    {
        $owner = $this->login(); [$version, $answers] = $this->version(); $body = $this->body($version, $answers);
        $this->postJson('/api/v1/quizzes/submit-offline', $body)->assertOk();
        $provider = Provider::create(['name' => 'Provider', 'email' => 'provider@example.test', 'is_active' => true, 'is_verified' => true]);
        $link = ProviderClientLink::create(['user_id' => $owner->id, 'provider_id' => $provider->id, 'provider_email' => $provider->email, 'verified_at' => now(), 'consent_quizzes' => true]);
        $this->postJson('/api/v1/quizzes/attempts/'.$body['id'].'/share', ['provider_id' => $provider->id, 'consent' => true])->assertOk();
        $service = app(ProviderReportService::class); $report = $service->generate($provider, $owner);
        $this->assertTrue($service->accessible($report, $link));
        $share = DB::table('quiz_shares')->first();
        $this->deleteJson('/api/v1/quizzes/attempts/'.$body['id'].'/grants/provider/'.$share->id)->assertOk();
        $this->assertFalse($service->accessible($report, $link));
        $this->postJson('/api/v1/quizzes/attempts/'.$body['id'].'/share', ['provider_id' => $provider->id, 'consent' => true])->assertOk();
        $this->assertFalse($service->accessible($report, $link));
    }
    public function test_revoked_versions_and_paused_flag_are_not_retryable_as_invalid_data(): void
    {
        $this->login(); [$version, $answers] = $this->version(); $body = $this->body($version, $answers);
        $version->update(['revoked_at' => now()]);
        $this->postJson('/api/v1/quizzes/submit-offline', $body)->assertStatus(410);
        \App\Models\FeatureFlag::updateOrCreate(['key' => 'quiz'], ['enabled' => false, 'description' => 'Quiz kill switch']);
        $this->postJson('/api/v1/sync/batch', ['operations' => [['type' => 'quiz_attempt', 'action' => 'upsert', 'data' => $body]]])->assertJsonPath('data.results.0.error_code', 'feature_paused');
    }
    public function test_fixtures_cover_all_four_instruments_and_pss_reverse_options(): void
    {
        $this->seed(\Database\Seeders\AssessmentFixtureSeeder::class);
        foreach (['phq-9' => 9, 'gad-7' => 7, 'pss-10' => 10, 'sleep' => 8] as $slug => $count) {
            $definition = app(AssessmentService::class)->publish(Quiz::where('slug', $slug)->firstOrFail())->definition;
            $this->assertCount($count, $definition['questions']);
            $answers = array_map(fn ($q) => $q['options'][0]['id'], $definition['questions']);
            $result = app(AssessmentService::class)->score($definition, $answers);
            $this->assertSame($slug === 'pss-10' ? 16 : 0, $result['score']);
        }
    }
    public function test_accepted_invitation_cannot_restore_a_revoked_friend_grant(): void
    {
        $owner = $this->login(); [$version, $answers] = $this->version(); $body = $this->body($version, $answers);
        $this->postJson('/api/v1/quizzes/submit-offline', $body)->assertOk();
        $url = $this->postJson('/api/v1/quizzes/attempts/'.$body['id'].'/invitations', ['consent' => true])->json('data.url');
        $friend = $this->login(); $friend->profile()->create(['id' => (string) Str::uuid(), 'name' => $friend->name, 'email' => $friend->email, 'onboarding_completed_at' => now()]);
        $share = $this->postJson('/api/v1/quizzes/invitations/claim', ['token' => basename($url), 'consent' => true])->assertOk()->json('data.share_id');
        $this->deleteJson('/api/v1/quizzes/shared/'.$share)->assertOk();
        $this->getJson('/api/v1/quizzes/shared')->assertJsonPath('data.total', 0);
        $this->getJson('/api/v1/quizzes/shared/'.$share)->assertOk();
        $this->login($owner); $this->deleteJson('/api/v1/quizzes/attempts/'.$body['id'].'/grants/friend/'.$share)->assertOk();
        $this->login($friend); $this->postJson('/api/v1/quizzes/invitations/claim', ['token' => basename($url), 'consent' => true])->assertStatus(410);
        $this->assertDatabaseCount('quiz_friend_shares', 1);
    }
    public function test_provider_inbox_is_audited_and_verification_withdrawal_removes_access(): void
    {
        $owner = $this->login(); [$version, $answers] = $this->version(); $body = $this->body($version, $answers);
        $this->postJson('/api/v1/quizzes/submit-offline', $body)->assertOk();
        $provider = Provider::create(['name' => 'Provider', 'email' => 'inbox@example.test', 'is_active' => true, 'is_verified' => true]);
        $link = ProviderClientLink::create(['user_id' => $owner->id, 'provider_id' => $provider->id, 'provider_email' => $provider->email, 'verified_at' => now(), 'consent_quizzes' => true]);
        $this->postJson('/api/v1/quizzes/attempts/'.$body['id'].'/share', ['provider_id' => $provider->id, 'consent' => true])->assertOk();
        app('auth')->forgetGuards(); $this->withToken($provider->createToken('portal')->plainTextToken);
        $share = $this->getJson('/api/v1/provider/quiz-shares')->assertOk()->assertJsonPath('total', 1)->json('data.0.id');
        $this->assertDatabaseHas('provider_access_logs', ['provider_id' => $provider->id, 'resource_id' => $body['id'], 'action' => 'list', 'resource_type' => 'quiz']);
        $this->getJson('/api/v1/provider/quiz-shares/'.$share)->assertOk()->assertJsonMissingPath('answers');
        $provider->update(['is_verified' => false]); app('auth')->forgetGuards();
        $this->getJson('/api/v1/provider/quiz-shares')->assertForbidden();
        $this->assertSame(0, app(QuizSharingService::class)->filterProvider(QuizAttempt::withoutGlobalScope('user'), $link)->count());
    }
    public function test_revoked_definition_does_not_break_other_catalog_items(): void
    {
        $this->login(); [$version] = $this->version('revoked'); $this->version('available');
        $version->update(['revoked_at' => now()]);
        $this->getJson('/api/v1/quizzes')->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.data.0.slug', 'available');
    }
    public function test_personal_pdf_is_owner_only_and_has_no_raw_answer_payload(): void
    {
        $owner = $this->login(); [$version, $answers] = $this->version(); $body = $this->body($version, $answers);
        $this->postJson('/api/v1/quizzes/submit-offline', $body)->assertOk();
        $response = $this->get('/api/v1/quizzes/attempts/'.$body['id'].'/pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $response->getContent());
        $html = view('quiz.result', ['result' => app(QuizSharingService::class)->summary(QuizAttempt::whereKey($body['id'])->first())])->render();
        $this->assertStringNotContainsString('Fixture 0', $html);
        $this->assertStringContainsString('Not a substitute for professional care.', $html);
        $this->login(); $this->get('/api/v1/quizzes/attempts/'.$body['id'].'/pdf')->assertNotFound();
    }
}
