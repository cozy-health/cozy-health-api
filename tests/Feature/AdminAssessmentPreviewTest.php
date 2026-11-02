<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminAssessmentPreviewTest extends TestCase
{
    use RefreshDatabase;

    private function admin(array $abilities = ['admin:content']): void
    {
        app('auth')->forgetGuards();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->withToken($admin->createToken('cozy-admin-management-v1', $abilities, now()->addMinutes(30))->plainTextToken);
    }

    private function definition(): array
    {
        return ['title' => 'Synthetic preview', 'slug' => 'synthetic-preview', 'type' => 'wellness', 'estimated_minutes' => 1,
            'disclaimer' => 'Engineering fixture only.', 'scoring_rules' => ['timeframe' => 'Today', 'crisis_threshold' => 3,
                'provenance' => ['license' => 'Original synthetic test fixture', 'approval_reference' => 'Isolated automated test only'],
                'bands' => [['max' => 2, 'label' => 'Lower', 'recommendation' => 'Lower fixture'], ['max' => 6, 'label' => 'Upper', 'recommendation' => 'Upper fixture']]],
            'questions' => [
                ['question' => 'Reverse scored?', 'options' => [['id' => 99, 'label' => 'First', 'score' => 3], ['id' => 99, 'label' => 'Second', 'score' => 0]]],
                ['question' => 'Second?', 'options' => [['label' => 'First', 'score' => 0], ['label' => 'Second', 'score' => 3]]],
            ]];
    }

    public function test_preview_uses_publication_scoring_without_persisting_answers_or_attempts(): void
    {
        $this->admin();
        $definition = $this->definition();
        $beforeAudit = DB::table('admin_audit_logs')->count();
        $preview = $this->postJson('/api/v1/admin/quizzes/preview', ['definition' => $definition, 'answers' => [0, 0]])
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.score', 3)->assertJsonPath('data.maximum_score', 6)
            ->assertJsonPath('data.interpretation', 'Upper')->assertJsonPath('data.is_crisis_flagged', true)
            ->assertJsonPath('data.recommendation', 'Upper fixture')->assertJsonMissingPath('data.result_snapshot');
        $this->assertDatabaseCount('quiz_attempts', 0);
        $this->assertDatabaseCount('admin_quiz_drafts', 0);
        $this->assertDatabaseCount('quiz_definitions', 0);
        $this->assertDatabaseCount('admin_audit_logs', $beforeAudit);

        $draft = $this->postJson('/api/v1/admin/quizzes', ['title' => $definition['title'], 'definition' => $definition])->assertCreated()->json('data');
        $this->postJson('/api/v1/admin/quizzes/'.$draft['id'].'/publish', ['approved' => true, 'expected_version' => $draft['version']])->assertOk();
        app('auth')->forgetGuards();
        $patient = User::factory()->create();
        $this->withToken($patient->createToken('device')->plainTextToken);
        $catalog = $this->getJson('/api/v1/quizzes')->assertOk()->assertJsonPath('data.data.0.slug', 'synthetic-preview')->json('data.data.0');
        $answers = array_map(fn ($question) => $question['options'][0]['id'], $catalog['questions']);
        $result = app(\App\Services\AssessmentService::class)->submit($patient, ['id' => (string) \Illuminate\Support\Str::uuid(),
            'definition_version' => $catalog['definition_version'], 'answers' => $answers, 'disclaimer_accepted' => true]);
        foreach (['score', 'maximum_score', 'interpretation', 'is_crisis_flagged'] as $field) {
            $this->assertSame($preview->json('data.'.$field), $result->$field);
        }
    }

    public function test_preview_validates_answers_definitions_and_capabilities(): void
    {
        $this->admin(['admin:metrics']);
        $body = ['definition' => $this->definition(), 'answers' => [0, 0]];
        $this->postJson('/api/v1/admin/quizzes/preview', $body)->assertForbidden();
        $this->admin();
        foreach ([[], [0], [0, 20], [-1, 0], ['bad', 0], ['first' => 0, 'second' => 0]] as $answers) {
            $this->postJson('/api/v1/admin/quizzes/preview', [...$body, 'answers' => $answers])->assertUnprocessable();
        }
        $body['definition']['scoring_rules']['bands'][1]['max'] = 4;
        $this->postJson('/api/v1/admin/quizzes/preview', $body)->assertUnprocessable()->assertJsonValidationErrors('definition');
    }

    public function test_preview_band_boundary_and_zero_are_calculated_from_option_scores(): void
    {
        $this->admin();
        $definition = $this->definition();
        $definition['questions'][0]['options'][0]['score'] = 2;
        $this->postJson('/api/v1/admin/quizzes/preview', ['definition' => $definition, 'answers' => [0, 0]])
            ->assertOk()->assertJsonPath('data.score', 2)->assertJsonPath('data.interpretation', 'Lower')->assertJsonPath('data.is_crisis_flagged', false);
        $this->postJson('/api/v1/admin/quizzes/preview', ['definition' => $definition, 'answers' => [1, 0]])
            ->assertOk()->assertJsonPath('data.score', 0)->assertJsonPath('data.interpretation', 'Lower');
    }

    public function test_publication_requires_rights_approval_and_rejects_stale_editor_actions(): void
    {
        $this->admin();
        $definition = $this->definition();
        unset($definition['scoring_rules']['provenance']);
        $draft = $this->postJson('/api/v1/admin/quizzes', ['title' => $definition['title'], 'definition' => $definition])->assertCreated()->json('data');
        $path = '/api/v1/admin/quizzes/'.$draft['id'];
        $this->postJson($path.'/publish', ['approved' => true, 'expected_version' => $draft['version']])->assertUnprocessable();
        $this->putJson($path, ['title' => $definition['title'], 'definition' => $this->definition(), 'expected_version' => $draft['version']])->assertOk();
        $this->postJson($path.'/publish', ['approved' => true, 'expected_version' => $draft['version']])->assertConflict();
        $this->deleteJson($path, ['expected_version' => $draft['version']])->assertConflict();
        $this->putJson($path, ['title' => $definition['title'], 'definition' => $definition, 'expected_version' => $draft['version']])->assertConflict();
        $current = $this->getJson('/api/v1/admin/quizzes')->assertOk()->json('data.data.0');
        $this->postJson($path.'/publish', ['expected_version' => $current['version']])->assertUnprocessable();
        $this->postJson($path.'/publish', ['approved' => true, 'expected_version' => $current['version']])->assertOk();
        $this->assertDatabaseCount('quizzes', 1);
    }
}
