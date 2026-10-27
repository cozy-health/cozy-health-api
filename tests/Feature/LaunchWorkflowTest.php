<?php
namespace Tests\Feature;
use App\Jobs\SendEmergencyContactAlert;
use App\Models\EmergencyEmailContact;
use App\Models\StudentApplication;
use App\Models\User;
use App\Services\StorePurchaseVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;
class LaunchWorkflowTest extends TestCase
{
    use RefreshDatabase;
    private function login(?User $user = null, bool $admin = false): User
    {
        $user ??= User::factory()->create();
        if ($admin) $user->forceFill(['role' => 'admin'])->save();
        app('auth')->forgetGuards();
        $this->withToken($user->createToken($admin ? 'cozy-admin-management-v1' : 'device', $admin ? ['admin:subscriptions'] : ['*'], now()->addMinutes(30))->plainTextToken);
        return $user;
    }
    public function test_launch_excludes_ai_even_when_a_persisted_flag_enables_it(): void
    {
        \App\Models\FeatureFlag::updateOrCreate(['key' => 'ai_assistant'], ['enabled' => true]);
        $response = $this->getJson('/api/v1/config')->assertOk()->assertJsonPath('flags.ai_assistant', false);
        $this->assertArrayNotHasKey('ai_disclosure', $response->json('consent.documents'));
    }
    public function test_unreviewed_legal_artifacts_cannot_be_published(): void
    {
        $this->artisan('legal:publish --apply')->assertExitCode(1);
        $this->assertDatabaseMissing('settings', ['key' => 'consent.enforce_blocking', 'value' => 'true']);
    }
    public function test_emergency_alert_requires_confirmation_and_retries_are_idempotent(): void
    {
        Queue::fake(); Mail::fake();
        config(['launch.emergency_email.enabled' => true, 'launch.emergency_email.daily_limit' => 2]);
        $user = $this->login();
        $id = (string) Str::uuid();
        $this->postJson('/api/v1/emergency-email', ['email' => 'contact@example.test', 'acknowledged' => true])->assertCreated();
        $this->postJson('/api/v1/emergency-email/alerts', ['request_id' => $id])->assertUnprocessable();
        EmergencyEmailContact::where('user_id', $user->id)->update(['confirmed_at' => now()]);
        $first = $this->postJson('/api/v1/emergency-email/alerts', ['request_id' => $id])->assertAccepted();
        $this->postJson('/api/v1/emergency-email/alerts', ['request_id' => $id])->assertAccepted()->assertJsonPath('alert.id', $first->json('alert.id'));
        $this->assertDatabaseCount('emergency_email_alerts', 1);
        $this->assertSame(2, DB::table('emergency_email_daily_quotas')->value('reserved'));
        $this->postJson('/api/v1/emergency-email/alerts', ['request_id' => (string) Str::uuid()])->assertStatus(429);
    }
    public function test_disabled_email_and_billing_do_not_send_or_grant_access(): void
    {
        Mail::fake(); $this->login();
        $this->postJson('/api/v1/emergency-email', ['email' => 'contact@example.test', 'acknowledged' => true])->assertStatus(503);
        $this->postJson('/api/v1/subscription/verify-receipt', ['platform' => 'apple', 'reference' => '123'])->assertStatus(503);
        $this->assertDatabaseCount('store_purchases', 0); Mail::assertNothingSent();
    }
    public function test_student_evidence_is_private_and_requires_a_scoped_reviewer(): void
    {
        Storage::fake('local'); config(['launch.student.enabled' => true]);
        $user = $this->login();
        $response = $this->post('/api/v1/student-verification', ['institution' => 'Example University', 'acknowledged' => '1', 'evidence' => UploadedFile::fake()->create('enrolment.pdf', 20, 'application/pdf')], ['Accept' => 'application/json'])->assertCreated();
        $id = $response->json('application.id');
        $this->assertArrayNotHasKey('evidence_path', $response->json('application'));
        $this->getJson('/api/v1/admin/student-applications/'.$id.'/evidence')->assertForbidden();
        $this->login(null, true);
        $this->postJson('/api/v1/admin/student-applications/'.$id.'/review', ['decision' => 'approved', 'note' => 'Email domain only', 'current_enrolment_verified' => false])->assertUnprocessable();
        $this->postJson('/api/v1/admin/student-applications/'.$id.'/review', ['decision' => 'approved', 'note' => 'Current dated enrolment verified', 'current_enrolment_verified' => true])->assertOk();
        $application = StudentApplication::findOrFail($id);
        $this->assertTrue($application->eligible_until->isFuture());
        $this->assertDatabaseCount('student_review_events', 1);
        $this->postJson('/api/v1/admin/student-applications/'.$id.'/review', ['decision' => 'rejected', 'note' => 'Repeat review'])->assertStatus(409);
    }
    public function test_purchase_binding_and_store_idempotency(): void
    {
        $user = $this->login();
        $account = $this->getJson('/api/v1/store/config')->assertOk()->json('account_token');
        config(['launch.billing_enabled' => true, 'launch.products' => ['premium.monthly' => ['tier' => 'premium', 'platform' => 'google']]]);
        $mock = \Mockery::mock(StorePurchaseVerifier::class);
        $mock->shouldReceive('verify')->andReturn(['reference' => 'store-token', 'identity' => 'store-token', 'product_id' => 'premium.monthly', 'account_token' => $account, 'expires_at' => now()->addMonth()->startOfSecond(), 'status' => 'active']);
        $mock->shouldReceive('acknowledge')->andReturnNull(); $this->app->instance(StorePurchaseVerifier::class, $mock);
        $body = ['platform' => 'google', 'reference' => 'store-token'];
        $this->postJson('/api/v1/subscription/verify-receipt', $body)->assertOk();
        $this->postJson('/api/v1/subscription/verify-receipt', $body)->assertOk();
        $this->assertDatabaseCount('store_purchases', 1); $this->assertDatabaseCount('subscription_statuses', 1);
        $this->login(); $this->postJson('/api/v1/subscription/verify-receipt', $body)->assertForbidden();
    }
    public function test_expired_student_evidence_is_removed_without_erasing_decision(): void
    {
        Storage::fake('local'); $user = $this->login(); Storage::disk('local')->put('student-evidence/test.pdf', 'test');
        $application = StudentApplication::create(['id' => (string) Str::uuid(), 'user_id' => $user->id, 'institution' => 'University', 'evidence_path' => 'student-evidence/test.pdf', 'evidence_delete_at' => now()->subDay(), 'status' => 'approved', 'eligible_until' => now()->addMonths(12)]);
        $this->artisan('launch:prune')->assertExitCode(0);
        Storage::disk('local')->assertMissing('student-evidence/test.pdf'); $this->assertNull($application->fresh()->evidence_path); $this->assertSame('approved', $application->fresh()->status);
    }
    public function test_alert_job_sends_once_and_does_not_treat_mail_acceptance_as_delivery(): void
    {
        $user = $this->login(); config(['launch.emergency_email.enabled' => true]);
        $contact = EmergencyEmailContact::create(['id' => (string) Str::uuid(), 'user_id' => $user->id, 'email' => 'contact@example.test', 'token_hash' => hash('sha256', 'token'), 'unsubscribe_hash' => hash('sha256', 'stop'), 'unsubscribe_token' => 'stop', 'token_expires_at' => now()->addDay(), 'confirmed_at' => now()]);
        $id = (string) Str::uuid();
        DB::table('emergency_email_alerts')->insert(['id' => $id, 'user_id' => $user->id, 'contact_id' => $contact->id, 'request_id' => (string) Str::uuid(), 'status' => 'queued', 'created_at' => now(), 'updated_at' => now()]);
        Mail::fake();
        $job = new SendEmergencyContactAlert($id); $job->handle(); $job->handle();
        Mail::assertSent(\App\Mail\BrandedNoticeMail::class, function ($mail) use ($user, $contact) {
            $text = implode(' ', $mail->paragraphs);
            return $mail->hasTo($contact->email) && str_contains($text, $user->name)
                && str_contains($text, 'not an emergency-service dispatch') && !str_contains($text, 'journal')
                && $mail->actions[0]['label'] === 'Stop these alerts';
        });
        Mail::assertSentCount(1);
        $this->assertDatabaseHas('emergency_email_alerts', ['id' => $id, 'status' => 'mail_service_accepted']);
    }
    public function test_account_deletion_queues_private_evidence_file_cleanup(): void
    {
        $user = $this->login();
        StudentApplication::create(['id' => (string) Str::uuid(), 'user_id' => $user->id, 'institution' => 'University', 'evidence_path' => 'student-evidence/private.pdf', 'status' => 'pending']);
        $user->forceDelete();
        $this->assertDatabaseHas('avatar_photo_deletions', ['disk' => 'local', 'object_key' => 'student-evidence/private.pdf']);
        $this->assertDatabaseCount('student_applications', 0);
    }
}
