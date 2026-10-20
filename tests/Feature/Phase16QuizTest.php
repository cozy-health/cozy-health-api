<?php

namespace Tests\Feature;

use App\Models\Provider;
use App\Models\ProviderClientLink;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class Phase16QuizTest extends TestCase
{
    use RefreshDatabase;

    private function login(?User $user = null): User
    {
        $user ??= User::factory()->create();
        app('auth')->forgetGuards();
        $this->withToken($user->createToken('device')->plainTextToken);

        return $user;
    }

    private function quiz(): array
    {
        $quiz = Quiz::create(['slug' => 'fixture-quiz', 'title' => 'Fixture assessment', 'type' => 'fixture', 'is_active' => true,
            'approved_at' => now(), 'disclaimer' => 'Fixture disclaimer. Not a substitute for professional care.',
            'scoring_rules' => ['bands' => [['max' => 2, 'label' => 'Fixture low'], ['max' => 10, 'label' => 'Fixture high']], 'crisis_threshold' => 5]]);
        $answers = [];
        for ($i = 0; $i < 2; $i++) {
            $question = $quiz->questions()->create(['question' => 'Fixture question '.$i, 'sort_order' => $i]);
            $question->options()->create(['label' => 'Low', 'score' => 0, 'sort_order' => 0]);
            $answers[] = $question->options()->create(['label' => 'High', 'score' => 3, 'sort_order' => 1])->id;
        }

        return [$quiz, $answers];
    }

    private function start(Quiz $quiz): string
    {
        return $this->postJson("/api/v1/quizzes/$quiz->id/start", ['disclaimer_accepted' => true])->assertOk()->json('data.attempt_id');
    }

    public function test_catalog_hides_unapproved_assessments_and_start_requires_disclaimer(): void
    {
        $this->login();
        [$quiz] = $this->quiz();
        $this->getJson('/api/v1/quizzes')->assertOk()->assertJsonPath('data.total', 1);
        $this->getJson('/api/v1/quizzes/'.$quiz->slug)->assertOk()->assertJsonCount(2, 'data.questions');
        $this->postJson("/api/v1/quizzes/$quiz->id/start", [])->assertUnprocessable();
        $quiz->update(['approved_at' => null]);
        $this->getJson('/api/v1/quizzes/'.$quiz->slug)->assertNotFound();
        $this->getJson('/api/v1/quizzes')->assertJsonPath('data.total', 0);
    }

    public function test_submission_scores_snapshot_not_client_values_and_is_idempotent(): void
    {
        $this->login();
        [$quiz, $answers] = $this->quiz();
        $id = $this->start($quiz);
        DB::table('quiz_options')->update(['score' => 99]);
        $body = ['attempt_id' => $id, 'answers' => $answers, 'score' => 0, 'is_crisis_flagged' => false];
        $this->postJson("/api/v1/quizzes/$quiz->id/submit", $body)->assertOk()->assertJsonPath('data.score', 6)
            ->assertJsonPath('data.interpretation', 'Fixture high')->assertJsonPath('data.is_crisis_flagged', true);
        $this->postJson("/api/v1/quizzes/$quiz->id/submit", $body)->assertOk();
        $this->assertDatabaseCount('quiz_attempts', 1);
        $this->postJson("/api/v1/quizzes/$quiz->id/submit", [...$body, 'answers' => array_reverse($answers)])->assertConflict();
        $this->getJson('/api/v1/quizzes/attempts')->assertOk()->assertJsonPath('data.total', 1);
        $this->getJson('/api/v1/quizzes/attempts/'.$id)->assertOk()->assertJsonPath('data.score', 6);
    }

    public function test_bad_answers_wrong_quiz_owner_and_expired_session_are_rejected(): void
    {
        $owner = $this->login();
        [$quiz, $answers] = $this->quiz();
        $id = $this->start($quiz);
        $path = "/api/v1/quizzes/$quiz->id/submit";
        $this->postJson($path, ['attempt_id' => $id, 'answers' => [$answers[0]]])->assertUnprocessable();
        $this->postJson($path, ['attempt_id' => $id, 'answers' => array_reverse($answers)])->assertUnprocessable();
        $this->postJson('/api/v1/quizzes/999/submit', ['attempt_id' => $id, 'answers' => $answers])->assertNotFound();
        $this->login();
        $this->postJson($path, ['attempt_id' => $id, 'answers' => $answers])->assertNotFound();
        $this->getJson('/api/v1/quizzes/attempts/'.$id)->assertNotFound();
        $this->login($owner);
        DB::table('quiz_sessions')->where('id', $id)->update(['expires_at' => now()->subMinute()]);
        $this->postJson($path, ['attempt_id' => $id, 'answers' => $answers])->assertStatus(410);
    }

    public function test_revoked_approval_prevents_submitting_a_started_assessment(): void
    {
        $this->login();
        [$quiz, $answers] = $this->quiz();
        $id = $this->start($quiz);
        $quiz->update(['approved_at' => null]);
        $this->postJson("/api/v1/quizzes/$quiz->id/submit", ['attempt_id' => $id, 'answers' => $answers])->assertNotFound();
        $this->assertDatabaseCount('quiz_attempts', 0);
    }

    public function test_retries_cannot_resurrect_deleted_assessment_results(): void
    {
        $this->login();
        [$quiz, $answers] = $this->quiz();
        $id = $this->start($quiz);
        $body = ['attempt_id' => $id, 'answers' => $answers];
        $this->postJson("/api/v1/quizzes/$quiz->id/submit", $body)->assertOk();
        QuizAttempt::findOrFail($id)->delete();
        $this->postJson("/api/v1/quizzes/$quiz->id/submit", $body)->assertStatus(410);
        $this->getJson('/api/v1/quizzes/attempts')->assertJsonPath('data.total', 0);
    }

    public function test_provider_share_requires_explicit_confirmation_and_existing_consent(): void
    {
        $user = $this->login();
        [$quiz, $answers] = $this->quiz();
        $id = $this->start($quiz);
        $this->postJson("/api/v1/quizzes/$quiz->id/submit", ['attempt_id' => $id, 'answers' => $answers])->assertOk();
        $provider = Provider::create(['name' => 'Fixture provider', 'email' => 'fixture@example.test', 'is_active' => true, 'is_verified' => true]);
        $link = ProviderClientLink::create(['user_id' => $user->id, 'provider_id' => $provider->id, 'provider_email' => $provider->email, 'verified_at' => now()]);
        $path = '/api/v1/quizzes/attempts/'.$id.'/share';
        $this->postJson($path, ['provider_id' => $provider->id])->assertUnprocessable();
        $this->postJson($path, ['provider_id' => $provider->id, 'consent' => true])->assertForbidden();
        $link->update(['consent_quizzes' => true]);
        $this->postJson($path, ['provider_id' => $provider->id, 'consent' => true])->assertOk()->assertJsonPath('data.shared', true);
        $this->assertDatabaseCount('quiz_shares', 1);
        $this->assertDatabaseHas('provider_access_logs', ['action' => 'shared', 'resource_id' => $id]);
        $link->update(['revoked_at' => now()]);
        $this->travel(1)->minutes();
        $this->postJson($path, ['provider_id' => $provider->id, 'consent' => true])->assertNotFound();
    }
}
