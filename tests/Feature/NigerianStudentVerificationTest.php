<?php

namespace Tests\Feature;

use App\Jobs\SendStudentSchoolNotice;
use App\Mail\BrandedNoticeMail;
use App\Models\{StudentApplication, User};
use App\Services\{StudentDomainPolicy, StudentEligibility};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Bus, DB, Mail};
use Tests\TestCase;

class NigerianStudentVerificationTest extends TestCase
{
    use RefreshDatabase;
    private function login(User $user, bool $admin = false): void
    {
        if ($admin) $user->forceFill(['role' => 'admin'])->save();
        app('auth')->forgetGuards();
        $this->withToken($user->createToken($admin ? 'cozy-admin-management-v1' : 'student', $admin ? ['admin:subscriptions'] : ['*'], now()->addMinutes(30))->plainTextToken);
        config(['launch.student.enabled' => true]);
    }

    public function test_domain_boundaries_blocking_and_exception_policy(): void
    {
        $policy = app(StudentDomainPolicy::class);
        foreach (['school.edu.ng', 'students.school.edu.ng'] as $domain) $this->assertNotNull($policy->institution($domain));
        foreach (['edu.ng', 'school.edu.ng.attacker.com', 'schooledu.ng', 'school.edu', 'gmail.com'] as $domain) $this->assertNull($policy->institution($domain));
        DB::table('student_email_domains')->insert(['domain' => 'school.edu.ng', 'institution' => 'Blocked', 'enabled' => false, 'version' => 1]);
        $this->assertNull($policy->institution('school.edu.ng'));
        DB::table('student_email_domains')->insert(['domain' => 'school.org', 'institution' => 'Approved Nigerian School', 'enabled' => true, 'version' => 1]);
        $this->assertSame('Approved Nigerian School', $policy->institution('school.org'));
        $this->assertNull($policy->institution('sub.school.org'));
    }

    public function test_email_code_ten_minutes_three_attempts_and_no_paid_access(): void
    {
        Mail::fake(); $user = User::factory()->create(); $this->login($user);
        $id = $this->postJson('/api/v1/student-verification/email', ['email' => 'learner@school.edu.ng'])->assertOk()->json('challenge_id');
        $row = DB::table('student_email_challenges')->find($id);
        $this->assertEqualsWithDelta(600, now()->diffInSeconds($row->expires_at), 2);
        preg_match('/\b[0-9]{6}\b/', Mail::sent(BrandedNoticeMail::class)->last()->paragraphs[0], $match);
        $this->assertNotSame($match[0], $row->code_hash);
        $this->postJson('/api/v1/student-verification/email', ['email' => 'learner@school.edu.ng'])->assertStatus(429);
        for ($i = 0; $i < 3; $i++) $this->postJson('/api/v1/student-verification/email/confirm', ['id' => $id, 'token' => '000000'])->assertUnprocessable();
        $this->postJson('/api/v1/student-verification/email/confirm', ['id' => $id, 'token' => $match[0]])->assertUnprocessable();
        $this->travel(61)->seconds();
        $id = $this->postJson('/api/v1/student-verification/email', ['email' => 'learner@school.edu.ng'])->assertOk()->json('challenge_id');
        preg_match('/\b[0-9]{6}\b/', Mail::sent(BrandedNoticeMail::class)->last()->paragraphs[0], $match);
        $this->postJson('/api/v1/student-verification/email/confirm', ['id' => $id, 'token' => $match[0]])->assertOk()->assertJsonPath('eligibility.active', true);
        $this->assertDatabaseMissing('student_email_challenges', ['id' => $id]);
        $this->assertDatabaseCount('subscription_statuses', 0);
        $this->assertNull(app(\App\Services\PlanAccess::class)->current($user)['plan']);
        $this->login(User::factory()->create());
        $this->postJson('/api/v1/student-verification/email', ['email' => 'learner@school.edu.ng'])->assertUnprocessable();
    }

    public function test_school_request_review_requires_capability_and_notifies_both_addresses(): void
    {
        Bus::fake(); $user = User::factory()->create(); $this->login($user);
        $payload = ['domain' => 'school.org', 'institution' => 'Nigerian School', 'website' => 'https://school.org'];
        $id = $this->postJson('/api/v1/student-school-requests', $payload)->assertCreated()->json('data.id');
        $this->postJson('/api/v1/student-school-requests', $payload)->assertCreated()->assertJsonPath('data.id', $id);
        Bus::assertDispatched(SendStudentSchoolNotice::class, fn ($job) => $job->recipient === 'team@cozyhealth.org');
        Bus::assertDispatched(SendStudentSchoolNotice::class, fn ($job) => $job->recipient === 'cozyhealth.hq@gmail.com');
        Bus::assertDispatchedTimes(SendStudentSchoolNotice::class, 2);
        Mail::fake();
        foreach (['team@cozyhealth.org', 'cozyhealth.hq@gmail.com'] as $recipient) {
            $job = new SendStudentSchoolNotice($id, $recipient);
            $job->handle(); $job->handle();
            Mail::assertSent(BrandedNoticeMail::class, fn ($mail) => $mail->hasTo($recipient));
        }
        Mail::assertSent(BrandedNoticeMail::class, 2);
        $this->getJson('/api/v1/admin/student-school-requests')->assertForbidden();
        $this->postJson('/api/v1/student-school-requests', [...$payload, 'domain' => 'gmail.com'])->assertUnprocessable();
        $this->login(User::factory()->create(), true);
        $review = ['decision' => 'approved', 'note' => 'Official Nigerian regulator and institutional email policy checked', 'institution_type' => 'university'];
        $this->postJson('/api/v1/admin/student-school-requests/'.$id.'/review', $review)->assertUnprocessable();
        $this->postJson('/api/v1/admin/student-school-requests/'.$id.'/review', [...$review, 'nigerian_tertiary_confirmed' => true])->assertOk();
        $this->postJson('/api/v1/admin/student-school-requests/'.$id.'/review', [...$review, 'nigerian_tertiary_confirmed' => true])->assertConflict();
        $this->assertDatabaseHas('student_email_domains', ['domain' => 'school.org', 'country' => 'NG', 'enabled' => true]);
        $this->assertNull(app(StudentEligibility::class)->current($user->id));
        $this->login($user);
        $this->getJson('/api/v1/student-school-requests')->assertOk()->assertJsonPath('data.0.status', 'approved');
    }

    public function test_documents_disabled_and_retention_preserves_unique_claim(): void
    {
        $user = User::factory()->create(); $this->login($user);
        $this->postJson('/api/v1/student-verification', [])->assertUnprocessable();
        $app = StudentApplication::create(['id' => (string) \Illuminate\Support\Str::uuid(), 'user_id' => $user->id, 'institution' => 'School', 'institution_email' => 'learner@school.edu.ng', 'status' => 'approved', 'eligible_until' => now()->subDays(91)]);
        DB::table('student_email_claims')->insert(['email_key' => StudentEligibility::emailKey('learner@school.edu.ng'), 'user_id' => $user->id, 'created_at' => now()]);
        $this->artisan('students:prune-verification')->assertSuccessful();
        $this->assertNull($app->fresh()->institution_email);
        $this->assertSame('expired', $app->fresh()->status);
        $this->assertDatabaseCount('student_email_claims', 1);
    }

    public function test_purchase_preflight_fails_closed_and_never_grants_paid_access(): void
    {
        $user = User::factory()->create(); $this->login($user);
        $payload = ['platform' => 'apple', 'product_id' => 'student.test'];
        $this->postJson('/api/v1/store/purchase-check', $payload)->assertStatus(503);
        config(['launch.billing_enabled' => true, 'launch.products' => ['student.test' => ['platform' => 'apple', 'tier' => 'student']]]);
        $this->getJson('/api/v1/store/config')->assertOk()->assertJsonPath('platform_ready.apple', false);
        $this->postJson('/api/v1/store/purchase-check', $payload)->assertStatus(503);
        foreach (['issuer_id', 'key_id', 'private_key', 'bundle_id', 'root_ca'] as $field) config(['launch.apple.'.$field => 'synthetic-config']);
        $this->postJson('/api/v1/store/purchase-check', $payload)->assertForbidden();
        $application = StudentApplication::create(['id' => (string) \Illuminate\Support\Str::uuid(), 'user_id' => $user->id, 'institution' => 'School', 'status' => 'approved', 'eligible_until' => now()->addMonth()]);
        $this->postJson('/api/v1/store/purchase-check', $payload)->assertOk()->assertJsonPath('allowed', true);
        $this->assertDatabaseCount('subscription_statuses', 0);
        $application->update(['eligible_until' => now()->subSecond()]);
        $this->postJson('/api/v1/store/purchase-check', $payload)->assertForbidden();
    }

    public function test_verified_paid_student_renewals_survive_email_eligibility_expiry(): void
    {
        Bus::fake(); $user = User::factory()->create(); $this->login($user);
        config(['launch.billing_enabled' => true, 'launch.products' => ['student.test' => ['platform' => 'apple', 'tier' => 'student']]]);
        $account = $this->getJson('/api/v1/store/config')->assertOk()->json('account_token');
        $application = StudentApplication::create(['id' => (string) \Illuminate\Support\Str::uuid(), 'user_id' => $user->id, 'institution' => 'School', 'status' => 'approved', 'eligible_until' => now()->addDay()]);
        $purchase = ['reference' => 'synthetic-reference', 'identity' => 'synthetic-subscription', 'account_token' => $account, 'product_id' => 'student.test', 'status' => 'active', 'expires_at' => now()->addMonth()];
        $verifier = \Mockery::mock(\App\Services\StorePurchaseVerifier::class);
        $verifier->shouldReceive('verify')->twice()->andReturn($purchase, [...$purchase, 'expires_at' => now()->addMonths(2)]);
        $verifier->shouldReceive('acknowledge')->twice()->andReturnNull();
        app()->instance(\App\Services\StorePurchaseVerifier::class, $verifier);
        $this->postJson('/api/v1/subscription/verify-receipt', ['platform' => 'apple', 'reference' => 'synthetic-reference'])->assertOk();
        $application->update(['eligible_until' => now()->subDay()]);
        $this->postJson('/api/v1/subscription/verify-receipt', ['platform' => 'apple', 'reference' => 'synthetic-reference'])->assertOk();
        $this->assertSame('student', app(\App\Services\PlanAccess::class)->current($user)['plan']);
        $this->assertDatabaseCount('store_purchases', 1);
        $this->assertNull(app(StudentEligibility::class)->current($user->id));
    }
}
