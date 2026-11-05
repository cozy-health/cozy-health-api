<?php

namespace Tests\Feature;

use App\Models\StudentApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\{DB, Mail, Bus};
use App\Mail\BrandedNoticeMail;
use App\Services\{StudentEligibility, StudentRenewalReminders};
use App\Jobs\SendStudentRenewalReminder;
use Tests\TestCase;

class StudentEligibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['launch.student.documents_enabled' => true]);
    }

    private function login(User $user, bool $admin = false): void
    {
        if ($admin) $user->forceFill(['role' => 'admin'])->save();
        app('auth')->forgetGuards();
        $this->withToken($user->createToken($admin ? 'cozy-admin-management-v1' : 'student', $admin ? ['admin:subscriptions'] : ['*'], now()->addMinutes(30))->plainTextToken);
    }

    private function approved(User $user, int $days): StudentApplication
    {
        return StudentApplication::create(['id' => (string) \Illuminate\Support\Str::uuid(), 'user_id' => $user->id, 'institution' => 'Synthetic College', 'status' => 'approved', 'eligible_until' => now()->addDays($days)]);
    }

    public function test_renewal_opens_thirty_days_early_and_preserves_the_current_period(): void
    {
        Storage::fake('local'); config(['launch.student.enabled' => true]);
        $user = User::factory()->create(); $previous = $this->approved($user, 30);
        $this->login($user);
        $response = $this->post('/api/v1/student-verification', ['institution' => 'Synthetic College', 'acknowledged' => '1', 'evidence' => UploadedFile::fake()->create('enrollment.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])->assertCreated();
        $this->getJson('/api/v1/student-verification')->assertOk()->assertJsonPath('eligibility.active', true)->assertJsonPath('eligibility.renewal_available', false);
        $this->login(User::factory()->create(), true);
        $this->postJson('/api/v1/admin/student-applications/'.$response->json('application.id').'/review', ['decision' => 'approved', 'note' => 'Current dated registration verified', 'current_enrolment_verified' => true])->assertOk();
        $this->assertTrue(StudentApplication::findOrFail($response->json('application.id'))->eligible_until->equalTo($previous->eligible_until->copy()->addMonthsNoOverflow(12)));
    }

    public function test_student_email_can_be_requested_without_uploading_a_document(): void
    {
        config(['launch.student.enabled' => true]);
        $this->login(User::factory()->create());
        $this->postJson('/api/v1/student-verification/email', ['email' => 'learner@unknown.example'])->assertUnprocessable()->assertJsonPath('code', 'student_domain_unapproved');
    }

    private function domain(): int
    {
        return DB::table('student_email_domains')->insertGetId(['domain' => 'students.synthetic.example', 'institution' => 'Synthetic College', 'enabled' => true, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function challenge(User $user, string $email = 'learner@students.synthetic.example'): array
    {
        Mail::fake(); config(['launch.student.enabled' => true]); $this->login($user);
        $response = $this->postJson('/api/v1/student-verification/email', ['email' => $email])->assertOk();
        $mail = Mail::sent(BrandedNoticeMail::class)->last();
        parse_str(parse_url($mail->actions[0]['url'], PHP_URL_QUERY), $params);
        preg_match('/\b[0-9]{6}\b/', $mail->paragraphs[0], $match);
        return ['id' => $response->json('challenge_id'), 'token' => $params['token'], 'code' => $match[0]];
    }

    public function test_email_verification_is_account_bound_single_use_and_does_not_grant_a_paid_plan(): void
    {
        $this->domain(); $user = User::factory()->create(); $challenge = $this->challenge($user);
        $this->getJson('/api/v1/student-email/confirm?id='.$challenge['id'].'&token='.$challenge['token'])->assertOk();
        $this->assertNull(app(StudentEligibility::class)->current($user->id));
        $this->login(User::factory()->create());
        $this->postJson('/api/v1/student-verification/email/confirm', $challenge)->assertUnprocessable();
        $this->login($user);
        $this->postJson('/api/v1/student-verification/email/confirm', ['id' => $challenge['id'], 'token' => $challenge['code']])->assertOk()->assertJsonPath('application.verification_method', 'email')->assertJsonPath('eligibility.active', true);
        $this->assertSame('learner@students.synthetic.example', app(StudentEligibility::class)->current($user->id)->institution_email);
        $this->assertSame(0, DB::table('subscription_statuses')->where('user_id', $user->id)->count());
        $this->assertNull(app(\App\Services\PlanAccess::class)->current($user)['plan']);
        $this->getJson('/api/v1/store/config')->assertOk()->assertJsonPath('student_eligible', true);
        $this->postJson('/api/v1/student-verification/email/confirm', $challenge)->assertUnprocessable();
        $this->assertDatabaseCount('student_review_events', 1);
        $this->login(User::factory()->create());
        $this->postJson('/api/v1/student-verification/email', ['email' => 'LEARNER@students.synthetic.example'])->assertUnprocessable();
    }

    public function test_invalid_codes_are_limited_expired_proofs_fail_and_domains_are_rechecked(): void
    {
        $id = $this->domain(); $user = User::factory()->create(); $challenge = $this->challenge($user);
        for ($i = 0; $i < 5; $i++) $this->postJson('/api/v1/student-verification/email/confirm', ['id' => $challenge['id'], 'token' => '000000'])->assertUnprocessable();
        $this->postJson('/api/v1/student-verification/email/confirm', $challenge)->assertUnprocessable();
        $this->assertNull(app(StudentEligibility::class)->current($user->id));
        $this->travel(61)->seconds(); $challenge = $this->challenge($user);
        $this->travel(10)->minutes();
        $this->postJson('/api/v1/student-verification/email/confirm', $challenge)->assertUnprocessable();
        // Advance past the existing email-request throttle before requesting fresh proof.
        $this->travel(61)->minutes(); $challenge = $this->challenge($user);
        DB::table('student_email_domains')->where('id', $id)->update(['enabled' => false]);
        $this->postJson('/api/v1/student-verification/email/confirm', $challenge)->assertUnprocessable();
    }

    public function test_domain_management_requires_capability_attestation_and_current_version(): void
    {
        $this->login(User::factory()->create());
        $this->getJson('/api/v1/admin/student-email-domains')->assertForbidden();
        $admin = User::factory()->create(); $this->login($admin, true);
        $payload = ['domain' => 'STUDENTS.SYNTHETIC.EXAMPLE', 'institution' => 'Synthetic College'];
        $this->postJson('/api/v1/admin/student-email-domains', $payload)->assertUnprocessable();
        $row = $this->postJson('/api/v1/admin/student-email-domains', [...$payload, 'nigerian_tertiary_confirmed' => true])->assertCreated()->json('data');
        $this->getJson('/api/v1/admin/student-email-domains')->assertOk()->assertJsonPath('data.data.0.domain', 'students.synthetic.example');
        $this->putJson('/api/v1/admin/student-email-domains/'.$row['id'], ['enabled' => false, 'version' => 1])->assertOk();
        $this->putJson('/api/v1/admin/student-email-domains/'.$row['id'], ['enabled' => true, 'version' => 1])->assertConflict();
        $this->assertDatabaseCount('admin_audit_logs', 2);
        $this->postJson('/api/v1/admin/student-email-domains', ['domain' => '*.synthetic.example', 'institution' => 'Synthetic College', 'nigerian_tertiary_confirmed' => true])->assertUnprocessable();
        $scoped = User::factory()->create(['role' => 'admin']); app('auth')->forgetGuards();
        $this->withToken($scoped->createToken('cozy-admin-management-v1', ['admin:content'], now()->addMinutes(30))->plainTextToken);
        $this->getJson('/api/v1/admin/student-email-domains')->assertForbidden();
    }

    public function test_only_exact_approved_student_domains_are_accepted(): void
    {
        $this->domain(); config(['launch.student.enabled' => true]);
        foreach (['learner@synthetic.example', 'learner@sub.students.synthetic.example', 'learner@students.synthetic.example.attacker.example'] as $email) {
            $this->login(User::factory()->create());
            $this->postJson('/api/v1/student-verification/email', ['email' => $email])->assertUnprocessable()->assertJsonPath('code', 'student_domain_unapproved');
        }
    }

    public function test_email_renewal_extends_existing_period_and_domain_removal_keeps_old_eligibility(): void
    {
        $domain = $this->domain(); $user = User::factory()->create(); $previous = $this->approved($user, 30);
        $challenge = $this->challenge($user);
        $this->postJson('/api/v1/student-verification/email/confirm', $challenge)->assertOk();
        $current = app(StudentEligibility::class)->current($user->id);
        $this->assertTrue($current->eligible_until->equalTo($previous->eligible_until->copy()->addMonthsNoOverflow(12)));
        DB::table('student_email_domains')->where('id', $domain)->update(['enabled' => false]);
        $this->assertSame($current->id, app(StudentEligibility::class)->current($user->id)->id);
    }

    public function test_eligibility_expiry_preserves_paid_student_access_until_the_paid_period_ends(): void
    {
        config(['plan_access.enabled' => true]);
        $user = User::factory()->create(); $this->approved($user, -1);
        DB::table('subscription_statuses')->insert(['id' => (string) \Illuminate\Support\Str::uuid(), 'user_id' => $user->id, 'plan' => 'student', 'status' => 'active',
            'started_at' => now()->subDays(10), 'expires_at' => now()->addDays(10), 'created_at' => now(), 'updated_at' => now()]);
        $this->login($user);
        $this->getJson('/api/v1/store/config')->assertOk()->assertJsonPath('student_eligible', false);
        $this->getJson('/api/v1/me/plan-access')->assertOk()->assertJsonPath('data.plan', 'student');
        $this->assertTrue(app(\App\Services\PlanAccess::class)->allows($user, 'trends_dashboard'));
        $this->travel(10)->days();
        $this->assertFalse(app(\App\Services\PlanAccess::class)->allows($user, 'trends_dashboard'));
        $this->assertTrue(app(\App\Services\PlanAccess::class)->allows($user, 'emergency'));
        $this->assertTrue(app(\App\Services\PlanAccess::class)->allows($user, 'existing_records'));
    }

    public function test_active_eligibility_blocks_early_email_renewal_and_proof_can_resume_without_exposing_secrets(): void
    {
        $this->domain(); $user = User::factory()->create(); $challenge = $this->challenge($user);
        $status = $this->getJson('/api/v1/student-verification')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $status->assertJsonPath('email_challenge.id', $challenge['id'])->assertJsonMissingPath('email_challenge.token_hash')->assertJsonMissingPath('email_challenge.code_hash')->assertJsonMissingPath('email_challenge.email');
        $this->postJson('/api/v1/student-verification/email/confirm', $challenge)->assertOk();
        $this->postJson('/api/v1/student-verification/email', ['email' => 'learner@students.synthetic.example'])->assertConflict();
        $this->getJson('/api/v1/student-verification')->assertOk()->assertJsonPath('email_challenge', null)->assertJsonPath('eligibility.renewal_available', false);
    }

    public function test_email_delivery_failure_removes_undelivered_proof_and_allows_document_fallback(): void
    {
        $this->domain(); config(['launch.student.enabled' => true]); Storage::fake('local');
        $user = User::factory()->create(); $this->login($user);
        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('Synthetic delivery outage'));
        $this->postJson('/api/v1/student-verification/email', ['email' => 'learner@students.synthetic.example'])->assertStatus(503);
        $this->assertDatabaseCount('student_email_challenges', 0);
        $this->post('/api/v1/student-verification', ['institution' => 'Synthetic College', 'acknowledged' => '1', 'evidence' => UploadedFile::fake()->create('registration.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])->assertCreated();
    }

    public function test_queued_reminders_do_not_send_to_changed_addresses_or_after_expiry(): void
    {
        config(['launch.student.enabled' => true]); Bus::fake(); Mail::fake();
        $user = User::factory()->create(); $application = $this->approved($user, 1);
        app(StudentRenewalReminders::class)->tick();
        $id = DB::table('student_renewal_reminders')->value('id');
        $user->update(['email' => 'different@synthetic.example']);
        (new SendStudentRenewalReminder($id))->handle();
        $this->assertDatabaseHas('student_renewal_reminders', ['id' => $id, 'status' => 'obsolete']);
        $this->travel(1)->days();
        $this->assertSame(1, app(StudentRenewalReminders::class)->tick());
        $expiredId = DB::table('student_renewal_reminders')->where('days', 0)->value('id');
        (new SendStudentRenewalReminder($expiredId))->handle();
        Mail::assertSent(BrandedNoticeMail::class, 1);
        $this->assertDatabaseHas('student_renewal_reminders', ['id' => $expiredId, 'status' => 'sent']);
    }

    public function test_rejected_renewal_preserves_current_eligibility_and_allows_fresh_application(): void
    {
        Storage::fake('local'); config(['launch.student.enabled' => true]);
        $user = User::factory()->create(); $previous = $this->approved($user, 20); $this->login($user);
        $response = $this->post('/api/v1/student-verification', ['institution' => 'Synthetic College', 'acknowledged' => '1', 'evidence' => UploadedFile::fake()->create('registration.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])->assertCreated();
        $this->login(User::factory()->create(), true);
        $this->postJson('/api/v1/admin/student-applications/'.$response->json('application.id').'/review', ['decision' => 'rejected', 'note' => 'Please show the current registration term'])->assertOk();
        $this->login($user);
        $this->getJson('/api/v1/student-verification')->assertOk()->assertJsonPath('application.status', 'rejected')->assertJsonPath('eligibility.active', true)->assertJsonPath('eligibility.renewal_available', true);
        $this->assertSame($previous->id, app(StudentEligibility::class)->current($user->id)->id);
    }

    public function test_reminders_deduplicate_retry_transport_failures_and_stop_after_renewal(): void
    {
        config(['launch.student.enabled' => true]); Mail::fake(); Bus::fake();
        $user = User::factory()->create(['email_verified_at' => now(), 'is_active' => true, 'email_invalid' => false]);
        $application = $this->approved($user, 30); $service = app(StudentRenewalReminders::class);
        $this->assertSame(1, $service->tick()); $this->assertSame(0, $service->tick());
        Bus::assertDispatched(SendStudentRenewalReminder::class, 1);
        $id = DB::table('student_renewal_reminders')->value('id');
        $job = new SendStudentRenewalReminder($id); $job->handle(); $job->handle();
        Mail::assertSent(BrandedNoticeMail::class, 1);
        $this->travel(23)->days(); $this->assertSame(1, $service->tick());
        $retryId = DB::table('student_renewal_reminders')->where('days', 7)->value('id');
        $mailFake = Mail::getFacadeRoot();
        Mail::swap(\Mockery::mock(\Illuminate\Mail\Mailer::class)->shouldReceive('to')->once()->andThrow(new \RuntimeException('Synthetic transport failure'))->getMock());
        try { (new SendStudentRenewalReminder($retryId))->handle(); $this->fail('Expected transport failure'); }
        catch (\RuntimeException $error) { $this->assertSame('Synthetic transport failure', $error->getMessage()); }
        $this->assertDatabaseHas('student_renewal_reminders', ['id' => $retryId, 'status' => 'pending']);
        Mail::swap($mailFake); (new SendStudentRenewalReminder($retryId))->handle(); Mail::assertSent(BrandedNoticeMail::class, 2);
        $this->travel(6)->days(); $this->assertSame(1, $service->tick());
        $obsolete = DB::table('student_renewal_reminders')->where('days', 1)->value('id');
        $renewal = StudentApplication::create(['id' => (string) \Illuminate\Support\Str::uuid(), 'user_id' => $user->id, 'institution' => 'Synthetic College', 'status' => 'pending']);
        DB::transaction(fn () => app(StudentEligibility::class)->approve($renewal, null, 'Renewed school email ownership'));
        (new SendStudentRenewalReminder($obsolete))->handle();
        Mail::assertSent(BrandedNoticeMail::class, 2);
        $this->assertDatabaseHas('student_renewal_reminders', ['id' => $obsolete, 'status' => 'obsolete']);
        $this->assertSame(0, $service->tick());
    }
}
