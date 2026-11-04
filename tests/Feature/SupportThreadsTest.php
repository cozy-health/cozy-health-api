<?php

namespace Tests\Feature;

use App\Mail\SupportReplyMail;
use App\Models\SupportMessage;
use App\Models\SupportReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class SupportThreadsTest extends TestCase
{
    use RefreshDatabase;

    private function asUser(User $user, array $abilities = ['*']): void
    {
        app('auth')->forgetGuards();
        $this->withToken($user->createToken($user->role === 'admin' ? 'cozy-admin-management-v1' : 'device', $abilities, now()->addMinutes(30))->plainTextToken);
    }

    private function staff(): User
    {
        $admin = User::factory()->create(['role' => 'admin']);
        config(['operations.support_reviewers' => [$admin->id]]);
        $this->asUser($admin, ['admin:support']);

        return $admin;
    }

    public function test_customer_submission_is_idempotent_and_does_not_accept_staff_fields(): void
    {
        $user = User::factory()->create();
        $this->asUser($user);
        $data = ['subject' => 'Question', 'message' => 'My private question', 'request_id' => (string) Str::uuid(), 'admin_notes' => 'forged', 'status' => 'resolved'];
        $first = $this->postJson('/api/v1/support/reports', $data)->assertCreated();
        $this->postJson('/api/v1/support/reports', $data)->assertCreated()->assertJsonPath('data.id', $first->json('data.id'));
        $this->assertDatabaseCount('support_reports', 1);
        $first->assertJsonMissingPath('data.admin_notes')->assertJsonPath('data.status', 'open');
        $this->postJson('/api/v1/support/reports', [...$data, 'message' => 'Different'])->assertConflict();
    }

    public function test_customer_can_read_only_own_reports_and_never_staff_notes(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $own = SupportReport::create(['user_id' => $user->id, 'subject' => 'Own', 'message' => 'Hello', 'admin_notes' => 'secret staff note']);
        $foreign = SupportReport::create(['user_id' => $other->id, 'subject' => 'Foreign', 'message' => 'Other']);
        $this->asUser($user);
        $this->getJson('/api/v1/support/reports')->assertOk()->assertJsonCount(1, 'data.data')->assertDontSee('secret staff note');
        $this->getJson('/api/v1/support/reports/'.$own->id)->assertOk()->assertJsonMissingPath('data.admin_notes')->assertDontSee('secret staff note');
        $this->getJson('/api/v1/support/reports/'.$foreign->id)->assertNotFound();
        $this->postJson('/api/v1/support/reports/'.$foreign->id.'/replies', ['body' => 'Attack', 'request_id' => (string) Str::uuid(), 'expected_version' => 0])->assertNotFound();
    }

    public function test_reply_round_trip_encrypts_body_preserves_resolution_and_reopens_for_customer(): void
    {
        Mail::fake();
        $user = User::factory()->create();
        $report = SupportReport::create(['user_id' => $user->id, 'subject' => 'Help', 'message' => 'Question', 'status' => 'resolved', 'resolved_at' => now()]);
        $this->staff();
        $data = ['body' => 'Private staff reply', 'request_id' => (string) Str::uuid(), 'expected_version' => 0];
        $this->postJson('/api/v1/admin/support/'.$report->id.'/replies', $data)->assertOk()->assertJsonPath('data.status', 'resolved');
        $this->postJson('/api/v1/admin/support/'.$report->id.'/replies', $data)->assertOk();
        $this->assertDatabaseCount('support_messages', 1);
        $this->assertDatabaseCount('app_notifications', 1);
        $this->assertNotSame('Private staff reply', DB::table('support_messages')->value('body'));
        $mail = new SupportReplyMail(SupportMessage::first()->id);
        $this->assertStringNotContainsString('Private staff reply', $mail->render());
        $this->assertStringNotContainsString('Question', $mail->render());
        $this->asUser($user);
        $this->getJson('/api/v1/support/reports/'.$report->id)->assertOk()->assertJsonPath('data.messages.0.body', 'Private staff reply')->assertJsonMissingPath('data.messages.0.delivery_id');
        $this->postJson('/api/v1/support/reports/'.$report->id.'/replies', ['body' => 'Thank you', 'request_id' => (string) Str::uuid(), 'expected_version' => 0])->assertConflict();
        $this->postJson('/api/v1/support/reports/'.$report->id.'/replies', ['body' => 'Thank you', 'request_id' => (string) Str::uuid(), 'expected_version' => 1])->assertOk()->assertJsonPath('data.status', 'open')->assertJsonPath('data.resolved_at', null);
    }

    public function test_support_requires_own_capability_and_current_assignment(): void
    {
        $admin = $this->staff();
        $this->asUser($admin, ['admin:moderation']);
        $this->getJson('/api/v1/admin/support')->assertForbidden();
        $this->asUser($admin, ['admin:support']);
        $this->getJson('/api/v1/admin/support')->assertOk();
        config(['operations.support_reviewers' => []]);
        $this->getJson('/api/v1/admin/support')->assertForbidden();
    }

    public function test_retry_requires_confirmed_failure_and_never_duplicates_reply(): void
    {
        Mail::fake();
        $report = SupportReport::create(['user_id' => User::factory()->create()->id, 'subject' => 'Help', 'message' => 'Question']);
        $this->staff();
        $this->postJson('/api/v1/admin/support/'.$report->id.'/replies', ['body' => 'Reply', 'request_id' => (string) Str::uuid(), 'expected_version' => 0])->assertOk();
        $message = SupportMessage::first();
        $retry = '/api/v1/admin/support/'.$report->id.'/messages/'.$message->id.'/retry';
        $this->postJson($retry)->assertConflict();
        DB::table('email_delivery_logs')->where('id', $message->delivery_id)->update(['status' => 'failed']);
        $this->postJson($retry)->assertAccepted();
        $this->postJson($retry)->assertConflict();
        $this->assertDatabaseCount('support_messages', 1);
        $this->assertDatabaseCount('email_delivery_logs', 2);
        $this->assertFalse((new SupportReplyMail($message->id))->shouldSend());
    }

    public function test_management_login_and_session_report_only_current_reviewer_assignments(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'password' => 'Fixture7!Strong']);
        config(['operations.support_reviewers' => [$admin->id], 'operations.privacy_reviewers' => []]);
        $token = $this->postJson('/api/v1/admin/auth/login', ['email' => $admin->email, 'password' => 'Fixture7!Strong', 'session_mode' => 'management-v1'])->assertOk()->json('token');
        app('auth')->forgetGuards();
        $this->withToken($token);
        $session = $this->getJson('/api/v1/admin/auth/session')->assertOk()->json('capabilities');
        $this->assertContains('admin:support', $session);
        $this->assertNotContains('admin:privacy', $session);
        config(['operations.support_reviewers' => []]);
        $this->assertNotContains('admin:support', $this->getJson('/api/v1/admin/auth/session')->assertOk()->json('capabilities'));
    }

    public function test_reply_and_encrypted_email_job_commit_together_and_stale_recipient_is_suppressed(): void
    {
        config(['mail.default' => 'array']);
        $user = User::factory()->create();
        $report = SupportReport::create(['user_id' => $user->id, 'subject' => 'Question', 'message' => 'Private initial content']);
        $this->staff();
        $this->postJson('/api/v1/admin/support/'.$report->id.'/replies', ['body' => 'Private reply content', 'request_id' => (string) Str::uuid(), 'expected_version' => 0])->assertOk();
        $this->assertDatabaseCount('jobs', 1);
        $payload = json_decode(DB::table('jobs')->value('payload'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('Private reply content', $payload['data']['command']);
        $queued = unserialize(decrypt($payload['data']['command']));
        $this->assertTrue($queued->mailable->shouldSend());
        $user->update(['email' => 'changed@example.test']);
        $this->assertFalse($queued->mailable->shouldSend());
    }

    public function test_public_support_link_has_generic_instructions_and_no_conversation_data(): void
    {
        $this->get('/support-inbox')->assertOk()->assertSee('cozyhealth://support-inbox', false)->assertSee('My support conversations')->assertHeader('Cache-Control', 'no-store, private');
    }
}
