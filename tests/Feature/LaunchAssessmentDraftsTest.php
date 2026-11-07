<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LaunchAssessmentDraftsTest extends TestCase
{
    use RefreshDatabase;

    private function prepare(): User
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $this->artisan('assessments:prepare-launch-drafts', ['--apply' => true, '--admin-id' => $admin->id])->assertSuccessful();
        $this->withToken($admin->createToken('cozy-admin-management-v1', ['admin:content'], now()->addMinutes(30))->plainTextToken);
        return $admin;
    }

    public function test_preparation_creates_reviewable_unpublished_drafts_and_preserves_edits_on_rerun(): void
    {
        $admin = $this->prepare();
        $rows = $this->getJson('/api/v1/admin/quizzes')->assertOk()->json('data.data');
        $this->assertCount(5, $rows);
        foreach ($rows as $row) {
            $this->assertSame('draft', $row['status']);
            $this->assertNull($row['quiz_id']);
            $this->assertCount(5, $row['definition']['questions']);
            $this->assertSame('wellness', $row['definition']['type']);
            $this->assertStringContainsString('not a diagnosis', $row['definition']['disclaimer']);
            $this->postJson('/api/v1/admin/quizzes/'.$row['id'].'/publish', ['approved' => true, 'expected_version' => $row['version']])
                ->assertUnprocessable()->assertJsonValidationErrors(['scoring_rules.provenance.license', 'scoring_rules.provenance.approval_reference']);
        }
        $row = $rows[0];
        $edited = $row['definition'];
        $edited['questions'][0]['question'] = 'Owner edited this question.';
        $this->putJson('/api/v1/admin/quizzes/'.$row['id'], ['title' => $row['title'], 'definition' => $edited, 'expected_version' => $row['version']])->assertOk();
        $before = DB::table('admin_quiz_drafts')->orderBy('id')->get()->toJson();
        $this->artisan('assessments:prepare-launch-drafts', ['--apply' => true, '--admin-id' => $admin->id])->assertSuccessful();
        $this->assertSame($before, DB::table('admin_quiz_drafts')->orderBy('id')->get()->toJson());
        $this->assertDatabaseCount('quizzes', 0);
        $this->assertDatabaseCount('quiz_definitions', 0);
        $this->assertDatabaseCount('quiz_attempts', 0);
        $this->assertSame(6, DB::table('admin_audit_logs')->where('action', 'save-draft')->where('actor_id', $admin->id)->count());
    }

    public function test_every_achievable_total_uses_the_proposed_bands_in_admin_preview(): void
    {
        $this->prepare();
        foreach ($this->getJson('/api/v1/admin/quizzes')->assertOk()->json('data.data') as $row) {
            $definition = $row['definition'];
            $positive = count($definition['questions'][0]['options']) === 5;
            $minimum = $positive ? 5 : 0;
            $maximum = $positive ? 25 : 15;
            foreach (range($minimum, $maximum) as $total) {
                // Independent previews must respect the real management request rate limit.
                $this->travel(2)->seconds();
                $remaining = $total - $minimum;
                $answers = [];
                for ($i = 0; $i < 5; $i++) {
                    $answer = min($positive ? 4 : 3, $remaining);
                    $answers[] = $answer;
                    $remaining -= $answer;
                }
                $label = $positive
                    ? match (true) { $total <= 10 => 'More support may help', $total <= 15 => 'Building well-being', $total <= 20 => 'Good well-being', default => 'Strong well-being' }
                    : match (true) { $total <= 4 => 'Few reported difficulties', $total <= 9 => 'Some reported difficulties', default => 'Frequent reported difficulties' };
                $this->postJson('/api/v1/admin/quizzes/preview', ['definition' => $definition, 'answers' => $answers])
                    ->assertOk()->assertJsonPath('data.score', $total)->assertJsonPath('data.maximum_score', $maximum)
                    ->assertJsonPath('data.interpretation', $label)->assertJsonPath('data.is_crisis_flagged', false);
            }
        }
        $this->assertDatabaseCount('quiz_attempts', 0);
        $this->assertDatabaseCount('quiz_definitions', 0);
    }

    public function test_preview_and_invalid_actor_do_not_create_content_and_other_environments_are_refused(): void
    {
        $this->artisan('assessments:prepare-launch-drafts')->assertSuccessful();
        $this->artisan('assessments:prepare-launch-drafts', ['--apply' => true])->assertFailed();
        $patient = User::factory()->create();
        $this->artisan('assessments:prepare-launch-drafts', ['--apply' => true, '--admin-id' => $patient->id])->assertFailed();
        $this->app->instance('env', 'production');
        config(['community.railway_project_id' => '33f9a938-49e3-42c5-83c7-633ee85760fa',
            'community.railway_environment_id' => '9ffd3fd1-ae52-4cc7-903e-35b529a151ae', 'app.url' => 'https://api-dev.cozyhealth.org']);
        $this->artisan('assessments:prepare-launch-drafts', ['--apply' => true])->assertFailed();
        $this->assertDatabaseCount('admin_quiz_drafts', 0);
    }
}
