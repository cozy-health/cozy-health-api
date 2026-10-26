<?php

namespace Tests\Feature;

use App\Models\StudentApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StudentReviewLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function authenticate(User $user, bool $admin = false): void
    {
        if ($admin) $user->forceFill(['role' => 'admin'])->save();
        app('auth')->forgetGuards();
        $this->withToken($user->createToken($admin ? 'cozy-admin-management-v1' : 'student-device', $admin ? ['admin:subscriptions'] : ['*'], now()->addMinutes(30))->plainTextToken);
    }

    private function submit(): StudentApplication
    {
        $response = $this->post('/api/v1/student-verification', [
            'institution' => 'Fictional University', 'acknowledged' => '1',
            'evidence' => UploadedFile::fake()->create('current-course-form.pdf', 10, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated();
        return StudentApplication::findOrFail($response->json('application.id'));
    }

    public function test_submitted_application_is_locked_and_decision_deletes_evidence_then_allows_fresh_submission(): void
    {
        Storage::fake('local'); config(['launch.student.enabled' => true]);
        foreach (['approved', 'rejected'] as $decision) {
            $student = User::factory()->create(); $this->authenticate($student);
            $application = $this->submit(); $path = $application->evidence_path;
            $this->assertSame(14, (int) now()->startOfDay()->diffInDays($application->evidence_delete_at->startOfDay()));
            $this->post('/api/v1/student-verification', ['institution' => 'Replacement', 'acknowledged' => '1', 'evidence' => UploadedFile::fake()->create('second.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])->assertConflict();
            $this->assertCount(1, Storage::disk('local')->allFiles('student-evidence'));
            $this->authenticate(User::factory()->create(), true);
            $this->postJson('/api/v1/admin/student-applications/'.$application->id.'/review', ['decision' => $decision, 'note' => 'Current term reviewed: '.$decision, 'current_enrolment_verified' => true])->assertOk();
            Storage::disk('local')->assertMissing($path);
            $this->assertNull($application->fresh()->evidence_path);
            $this->getJson('/api/v1/admin/student-applications/'.$application->id.'/evidence')->assertNotFound();
            $this->authenticate($student);
            $this->getJson('/api/v1/student-verification')->assertOk()->assertJsonPath('application.status', $decision)->assertJsonPath('application.review_note', 'Current term reviewed: '.$decision);
            if ($decision === 'rejected') { $this->travel(1)->days(); $this->assertNotSame($application->id, $this->submit()->id); }
            else { $this->assertSame(12, (int) now()->startOfDay()->diffInMonths($application->fresh()->eligible_until->startOfDay())); $this->travel(1)->years(); $this->submit(); }
            Storage::disk('local')->deleteDirectory('student-evidence');
        }
    }

    public function test_expired_pending_application_cannot_be_approved_and_does_not_block_resubmission(): void
    {
        Storage::fake('local'); config(['launch.student.enabled' => true]);
        $student = User::factory()->create(); $this->authenticate($student);
        $application = $this->submit(); $path = $application->evidence_path;
        $this->travel(14)->days();
        $this->authenticate(User::factory()->create(), true);
        $this->postJson('/api/v1/admin/student-applications/'.$application->id.'/review', ['decision' => 'approved', 'note' => 'Current term confirmed', 'current_enrolment_verified' => true])->assertUnprocessable();
        $this->authenticate($student);
        $this->getJson('/api/v1/student-verification')->assertOk()->assertJsonPath('application.status', 'expired');
        Storage::disk('local')->assertMissing($path);
        $this->submit();
    }

    public function test_failed_document_deletion_closes_access_and_retains_a_durable_retry(): void
    {
        Storage::fake('local'); config(['launch.student.enabled' => true]);
        $student = User::factory()->create(); $this->authenticate($student);
        $application = $this->submit(); $path = $application->evidence_path;
        $disk = Storage::disk('local');
        $failingDisk = \Mockery::mock($disk)->makePartial();
        $failingDisk->shouldReceive('delete')->once()->with($path)->andReturn(false);
        Storage::set('local', $failingDisk);
        $this->authenticate(User::factory()->create(), true);
        $this->postJson('/api/v1/admin/student-applications/'.$application->id.'/review', ['decision' => 'rejected', 'note' => 'Document does not show the current term'])->assertOk()->assertJsonPath('evidence_deleted', false);
        $this->getJson('/api/v1/admin/student-applications/'.$application->id.'/evidence')->assertNotFound();
        $this->assertSame($path, $application->fresh()->evidence_path);
        Storage::set('local', $disk);
        $this->artisan('launch:prune')->assertExitCode(0);
        Storage::disk('local')->assertMissing($path);
        $this->assertNull($application->fresh()->evidence_path);
        $this->assertSame('rejected', $application->fresh()->status);
    }

    public function test_same_second_resubmission_and_decisions_show_the_correct_application(): void
    {
        $this->freezeTime(); Storage::fake('local'); config(['launch.student.enabled' => true]);
        $student = User::factory()->create(); $admin = User::factory()->create();
        $this->authenticate($student); $first = $this->submit();
        $this->authenticate($admin, true);
        $this->postJson('/api/v1/admin/student-applications/'.$first->id.'/review', ['decision' => 'rejected', 'note' => 'Use a current document'])->assertOk();
        $this->authenticate($student); $second = $this->submit();
        $this->getJson('/api/v1/student-verification')->assertOk()->assertJsonPath('application.id', $second->id)->assertJsonPath('application.status', 'pending');
        $this->authenticate($admin, true);
        $this->postJson('/api/v1/admin/student-applications/'.$second->id.'/review', ['decision' => 'rejected', 'note' => 'Current semester still missing'])->assertOk();
        $this->authenticate($student);
        $this->getJson('/api/v1/student-verification')->assertOk()->assertJsonPath('application.id', $second->id)->assertJsonPath('application.review_note', 'Current semester still missing');
    }
}
