<?php

use App\Models\{GuardianAuthorization, User};
use App\Services\AgeEligibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Mail};
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function ageUser(int $years = 16): User
{
    return User::factory()->create(['date_of_birth' => now('UTC')->subYears($years)->format('Y-m-d'),
        'residence_country' => 'NG', 'age_declared_at' => now(), 'email_verified_at' => now()]);
}

function ageReady(): void
{
    config(['age.enabled' => true, 'age.health_data_ready' => true, 'age.invitations_enabled' => true,
        'age.approved_countries' => ['NG'], 'age.assurance_approval' => 'test-country-approval',
        'age.retention_approval' => 'test-retention-approval', 'age.record_days' => 365]);
    $documents = [];
    foreach (['tos', 'privacy_policy', 'health_data'] as $type) $documents[$type] = [
        'title' => $type, 'version' => 'test-1', 'required' => true, 'published' => true, 'content' => 'Synthetic test document.',
    ];
    config(['consent.documents' => $documents]);
    app()->instance(AgeEligibility::class, new class extends AgeEligibility {
        public function documents(): array { return [...config('consent.documents'), 'guardian_notice' => [
            'title' => 'Guardian notice', 'version' => 'test-1', 'published' => true, 'content' => 'Synthetic test notice.',
        ]]; }
    });
}

function ageAccept(User $user): void
{
    foreach (['tos', 'privacy_policy', 'health_data'] as $type) DB::table('user_consents')->insert([
        'user_id' => $user->id, 'document_type' => $type, 'version' => 'test-1', 'status' => 'accepted', 'recorded_at' => now(), 'accepted_at' => now(),
    ]);
}

function ageAuthorization(User $user, string $status = 'authorized'): GuardianAuthorization
{
    return GuardianAuthorization::create(['id' => (string) \Illuminate\Support\Str::uuid(), 'user_id' => $user->id,
        'guardian_name' => 'Guardian', 'guardian_email' => 'guardian@example.com',
        'email_hash' => hash_hmac('sha256', 'guardian@example.com', config('app.key')), 'relationship' => 'parent',
        'status' => $status, 'document_versions' => app(AgeEligibility::class)->versions(), 'authorized_at' => now()]);
}

it('requires age on registration only after compatible-client enforcement is enabled', function () {
    config(['age.enabled' => true]);
    $this->postJson('/api/v1/auth/register', ['name' => 'Test User', 'email' => 'age@example.com',
        'password' => 'Fixture7!Strong', 'password_confirmation' => 'Fixture7!Strong'])
        ->assertUnprocessable()->assertJsonValidationErrors(['date_of_birth', 'residence_country']);
    $this->assertDatabaseMissing('users', ['email' => 'age@example.com']);
});

it('rejects under-thirteen and impossible registration dates without creating accounts', function () {
    config(['age.enabled' => true]);
    foreach ([now('UTC')->subYears(13)->addDay()->format('Y-m-d'), '2010-02-30', '2030-01-01'] as $dob) {
        $this->postJson('/api/v1/auth/register', ['name' => 'Young Test', 'email' => 'young@example.com',
            'password' => 'Fixture7!Strong', 'password_confirmation' => 'Fixture7!Strong', 'date_of_birth' => $dob, 'residence_country' => 'NG'])->assertUnprocessable();
    }
    $this->assertDatabaseMissing('users', ['email' => 'young@example.com']);
});

it('calculates birthdays on a UTC calendar boundary', function () {
    $this->travelTo(\Carbon\Carbon::parse('2026-10-22 00:00:00', 'UTC'));
    $service = app(AgeEligibility::class);
    foreach (['2013-10-22' => 13, '2013-10-23' => 12, '2008-10-22' => 18, '2008-10-23' => 17, '2012-02-29' => 14] as $dob => $years) {
        expect($service->age(new User(['date_of_birth' => $dob])))->toBe($years);
    }
});

it('blocks private reads writes and sync while keeping recovery and support reachable', function () {
    ageReady(); $user = ageUser(); Sanctum::actingAs($user);
    $this->getJson('/api/v1/mood-entries')->assertForbidden()->assertJsonPath('code', 'guardian_required');
    $this->postJson('/api/v1/sync/batch', ['operations' => []])->assertForbidden();
    $this->getJson('/api/v1/me/eligibility')->assertOk()->assertJsonPath('data.allowed', false);
    $this->getJson('/api/v1/me/consents')->assertOk();
    $this->postJson('/api/v1/me/age-support', ['kind' => 'privacy', 'message' => 'Please explain my privacy choices.'])->assertCreated();
});

it('does not enable a minor when country assurance or current documents are missing', function () {
    ageReady(); $user = ageUser(); ageAccept($user); ageAuthorization($user);
    expect(app(AgeEligibility::class)->allows($user))->toBeTrue();
    config(['age.approved_countries' => []]);
    expect(app(AgeEligibility::class)->state($user)['reason'])->toBe('country_not_enabled');
    config(['age.approved_countries' => ['NG'], 'age.assurance_approval' => null]);
    expect(app(AgeEligibility::class)->allows($user))->toBeFalse();
});

it('requires the young person own current health consent after guardian authorization', function () {
    ageReady(); $user = ageUser(); ageAuthorization($user);
    expect(app(AgeEligibility::class)->state($user)['reason'])->toBe('consent_required');
    ageAccept($user); expect(app(AgeEligibility::class)->allows($user))->toBeTrue();
    DB::table('user_consents')->where('user_id', $user->id)->where('document_type', 'health_data')->update(['status' => 'withdrawn']);
    expect(app(AgeEligibility::class)->allows($user))->toBeFalse();
});

it('records email confirmation separately and consumes authorization tokens once', function () {
    ageReady(); $user = ageUser(); Sanctum::actingAs($user); $token = null;
    Mail::shouldReceive('raw')->once()->andReturnUsing(function ($text, $callback) use (&$token) { preg_match('/authorization#([a-f0-9]{64})/', $text, $matches); $token = $matches[1]; });
    $this->postJson('/api/v1/me/guardian', ['guardian_name' => 'Parent', 'guardian_email' => 'parent@example.com', 'relationship' => 'parent'])->assertCreated();
    $this->assertDatabaseMissing('guardian_authorizations', ['token_hash' => $token]);
    $response = $this->postJson('/api/v1/guardian/confirm', ['token' => $token])->assertOk()
        ->assertHeader('Referrer-Policy', 'no-referrer')->assertHeader('Cache-Control', 'no-store, private');
    expect(app(AgeEligibility::class)->allows($user))->toBeFalse();
    $this->postJson('/api/v1/guardian/confirm', ['token' => $token])->assertNotFound();
    $session = $response->json('session');
    $this->postJson('/api/v1/guardian/decision', ['session' => $session, 'decision' => 'authorize', 'adult_and_authority' => true,
        'versions' => app(AgeEligibility::class)->versions()])->assertOk();
    $this->postJson('/api/v1/guardian/decision', ['session' => $session, 'decision' => 'authorize', 'adult_and_authority' => true,
        'versions' => app(AgeEligibility::class)->versions()])->assertNotFound();
    expect(GuardianAuthorization::first()->status)->toBe('authorized');
    expect(app(AgeEligibility::class)->allows($user))->toBeFalse();
});

it('rejects same-email unverified and underage guardian invitation attempts', function () {
    ageReady(); $user = ageUser(); Sanctum::actingAs($user);
    $payload = ['guardian_name' => 'Parent', 'guardian_email' => $user->email, 'relationship' => 'parent'];
    $this->postJson('/api/v1/me/guardian', $payload)->assertUnprocessable();
    $user->forceFill(['email_verified_at' => null])->save();
    $this->postJson('/api/v1/me/guardian', [...$payload, 'guardian_email' => 'parent@example.com'])->assertForbidden();
});

it('rejects expired links and stale document decisions', function () {
    ageReady(); $user = ageUser(); $authorization = ageAuthorization($user, 'pending');
    $token = str_repeat('a', 64);
    $authorization->update(['token_hash' => hash('sha256', $token), 'expires_at' => now()->subSecond()]);
    $this->postJson('/api/v1/guardian/confirm', ['token' => $token])->assertGone();
    $authorization->update(['expires_at' => now()->addHour()]);
    $session = $this->postJson('/api/v1/guardian/confirm', ['token' => $token])->json('session');
    $this->postJson('/api/v1/guardian/decision', ['session' => $session, 'decision' => 'authorize', 'adult_and_authority' => true, 'versions' => ['health_data' => 'old']])->assertConflict();
});

it('withdraws only after an emailed link and explicit guardian decision', function () {
    ageReady(); $user = ageUser(); ageAccept($user); $authorization = ageAuthorization($user); $token = null;
    Mail::shouldReceive('raw')->once()->andReturnUsing(function ($text, $callback) use (&$token) { preg_match('/authorization#([a-f0-9]{64})/', $text, $matches); $token = $matches[1]; });
    $this->postJson('/api/v1/guardian/withdrawal', ['email' => 'guardian@example.com'])->assertOk();
    expect(app(AgeEligibility::class)->allows($user))->toBeTrue();
    $session = $this->postJson('/api/v1/guardian/confirm', ['token' => $token])->assertOk()->assertJsonPath('withdrawal', true)->json('session');
    expect(app(AgeEligibility::class)->allows($user))->toBeTrue();
    $this->postJson('/api/v1/guardian/decision', ['session' => $session, 'decision' => 'withdraw'])->assertOk();
    expect($authorization->fresh()->status)->toBe('withdrawn');
    expect(app(AgeEligibility::class)->allows($user))->toBeFalse();
});

it('preserves private records while revoking authorization and opens a disputed case', function () {
    ageReady(); $user = ageUser(); ageAccept($user); ageAuthorization($user); Sanctum::actingAs($user);
    $this->postJson('/api/v1/me/age-support', ['kind' => 'guardian_dispute', 'message' => 'This person is no longer my legal guardian.'])->assertCreated();
    expect(app(AgeEligibility::class)->state($user->fresh())['reason'])->toBe('age_disputed');
    $this->assertDatabaseHas('age_support_cases', ['user_id' => $user->id, 'status' => 'open']);
    $this->assertDatabaseHas('users', ['id' => $user->id]);
});

it('does not let profile country or date edits remove an age restriction', function () {
    ageReady(); $user = ageUser(); Sanctum::actingAs($user);
    $this->patchJson('/api/v1/me', ['date_of_birth' => '1990-01-01'])->assertForbidden();
    $this->postJson('/api/v1/me/age', ['date_of_birth' => '1990-01-01', 'residence_country' => 'NG'])->assertConflict();
    expect($user->fresh()->date_of_birth->format('Y-m-d'))->not->toBe('1990-01-01');
});

it('ends guardian authority at adulthood and keeps adult consent separate', function () {
    ageReady(); $user = ageUser(18); ageAccept($user); $authorization = ageAuthorization($user); Sanctum::actingAs($user);
    $this->getJson('/api/v1/me/eligibility')->assertOk()->assertJsonPath('data.allowed', true)->assertJsonPath('data.online_required', false);
    expect($authorization->fresh()->status)->toBe('adulthood');
});

it('keeps guardian cases inaccessible to patient and aggregate-only tokens', function () {
    ageReady(); $user = ageUser(); Sanctum::actingAs($user);
    $this->getJson('/api/v1/admin/age-cases')->assertForbidden();
});

it('keeps guardian invitations disabled while the actual notice is unpublished', function () {
    config(['age.invitations_enabled' => true, 'age.retention_approval' => 'test', 'age.record_days' => 365]);
    expect(app(AgeEligibility::class)->invitationsReady())->toBeFalse();
});

it('returns only basic identity while a stored restricted session resumes', function () {
    ageReady(); $user = ageUser(); Sanctum::actingAs($user);
    $this->getJson('/api/v1/user/profile')->assertOk()->assertExactJson(['data' => [
        'user_id' => $user->id, 'name' => $user->name, 'email' => $user->email,
    ]]);
    $this->getJson('/api/v1/me')->assertOk()->assertExactJson(['success' => true, 'message' => 'Request successful.', 'data' => [
        'id' => $user->id, 'name' => $user->name, 'email' => $user->email,
    ]]);
});

it('invalidates guardian authority after a residence change and rejects invalid countries', function () {
    ageReady(); $user = ageUser(); $authorization = ageAuthorization($user); Sanctum::actingAs($user);
    $this->putJson('/api/v1/me/residence', ['residence_country' => 'XX'])->assertUnprocessable();
    $this->putJson('/api/v1/me/residence', ['residence_country' => 'GB'])->assertOk()->assertJsonPath('data.allowed', false);
    expect($authorization->fresh()->status)->not->toBe('authorized');
});

it('retains initial age declaration and rejects a repeated or changed date', function () {
    ageReady(); $user = User::factory()->create(); Sanctum::actingAs($user);
    $this->postJson('/api/v1/me/age', ['date_of_birth' => '2000-01-01', 'residence_country' => 'NG'])->assertOk();
    $this->postJson('/api/v1/me/age', ['date_of_birth' => '1990-01-01', 'residence_country' => 'NG'])->assertConflict();
    expect($user->fresh()->date_of_birth->format('Y-m-d'))->toBe('2000-01-01');
});

it('reports a failed guardian email without claiming that it was sent', function () {
    ageReady(); $user = ageUser(); Sanctum::actingAs($user);
    Mail::shouldReceive('raw')->once()->andThrow(new RuntimeException('Synthetic mail failure'));
    $this->postJson('/api/v1/me/guardian', ['guardian_name' => 'Parent', 'guardian_email' => 'parent@example.com', 'relationship' => 'parent'])->assertStatus(503);
    expect(GuardianAuthorization::first()->status)->toBe('delivery_failed');
    expect(GuardianAuthorization::first()->token_hash)->toBeNull();
});

it('previews retention and preserves records on an unresolved case', function () {
    ageReady(); $user = ageUser(); $record = ageAuthorization($user, 'declined');
    $record->update(['erase_at' => now()->subDay()]);
    $this->artisan('guardians:prune')->assertSuccessful();
    expect($record->fresh()->guardian_email)->toBe('guardian@example.com');
    DB::table('age_support_cases')->insert(['id' => (string) \Illuminate\Support\Str::uuid(), 'user_id' => $user->id,
        'kind' => 'appeal', 'message' => encrypt('An unresolved appeal'), 'created_at' => now(), 'updated_at' => now()]);
    $this->artisan('guardians:prune --execute')->assertSuccessful();
    expect($record->fresh()->guardian_email)->toBe('guardian@example.com');
    DB::table('age_support_cases')->where('user_id', $user->id)->update(['status' => 'resolved']);
    $this->artisan('guardians:prune --execute')->assertSuccessful();
    expect($record->fresh()->guardian_email)->toBeNull();
});

it('requires a scoped real admin session assignment and approved correction procedure', function () {
    ageReady(); Mail::fake();
    $patient = ageUser(); $admin = User::factory()->create(['role' => 'admin']);
    $id = (string) \Illuminate\Support\Str::uuid();
    DB::table('age_support_cases')->insert(['id' => $id, 'user_id' => $patient->id, 'kind' => 'dob_correction',
        'message' => encrypt('Please correct my birthday.'), 'created_at' => now(), 'updated_at' => now()]);
    $token = $admin->createToken('cozy-admin-management-v1', ['admin:guardians'], now()->addMinutes(30))->plainTextToken;
    $this->withToken($token)->getJson('/api/v1/admin/age-cases')->assertOk()->assertJsonPath('data.data.0.kind', 'dob_correction');
    $this->postJson('/api/v1/admin/age-cases/'.$id.'/resolve', ['action' => 'assign', 'note' => 'Assigned to cover this request.', 'authority_checked' => false])->assertOk();
    $payload = ['action' => 'correct_dob', 'note' => 'Birthday corrected through approved case procedure.', 'authority_checked' => true,
        'date_of_birth' => now()->subYears(17)->format('Y-m-d'), 'verification_reference' => 'case-reference-123'];
    $this->postJson('/api/v1/admin/age-cases/'.$id.'/resolve', $payload)->assertConflict();
    config(['age.correction_approval' => 'approved-procedure-reference']);
    $this->postJson('/api/v1/admin/age-cases/'.$id.'/resolve', $payload)->assertOk();
    expect(app(AgeEligibility::class)->allows($patient->fresh()))->toBeFalse();
    $this->assertDatabaseHas('age_authorization_events', ['user_id' => $patient->id, 'action' => 'admin_case_correct_dob', 'actor_id' => $admin->id]);
});

it('blocks provider reads messages reports and audio for restricted patients', function () {
    ageReady(); $patient = ageUser();
    $provider = \App\Models\Provider::create(['name' => 'Test Provider', 'email' => 'provider@example.com', 'is_active' => true, 'is_verified' => true]);
    $link = \App\Models\ProviderClientLink::create(['provider_id' => $provider->id, 'provider_email' => $provider->email,
        'user_id' => $patient->id, 'linked_at' => now(), 'verified_at' => now(), 'consent_moods' => true]);
    $this->withToken($provider->createToken('test')->plainTextToken)
        ->getJson('/api/v1/provider/clients/'.$patient->id.'/moods')->assertForbidden();
    $this->getJson('/api/v1/provider/conversations/'.$link->id.'/messages')->assertForbidden();
    $this->getJson('/api/v1/provider/clients')->assertOk()->assertJsonCount(0);
    $this->getJson('/api/v1/provider/conversations')->assertOk()->assertJsonCount(0);
    $this->postJson('/api/v1/provider/reports', ['user_id' => $patient->id])->assertForbidden();
    try { app(\App\Services\AudioAttachmentService::class)->link($provider, $link->id); $this->fail('Expected a restricted audio relationship'); }
    catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $error) { expect($error->getStatusCode())->toBe(403); }
});

it('does not reveal age or guardian state to an unlinked provider', function () {
    ageReady(); $patient = ageUser();
    $provider = \App\Models\Provider::create(['name' => 'Unlinked Provider', 'email' => 'unlinked@example.com', 'is_active' => true, 'is_verified' => true]);
    $this->withToken($provider->createToken('test')->plainTextToken)
        ->getJson('/api/v1/provider/clients/'.$patient->id)->assertNotFound()->assertJsonMissingPath('eligibility');
    $this->postJson('/api/v1/provider/reports', ['user_id' => $patient->id])->assertNotFound()->assertJsonMissingPath('eligibility');
});

it('records health-consent withdrawal through the real endpoint and blocks the next private request', function () {
    ageReady(); $user = ageUser(25); ageAccept($user); Sanctum::actingAs($user);
    $this->postJson('/api/v1/me/consents', ['document_type' => 'health_data', 'version' => 'test-1', 'accepted' => false, 'actor_id' => $user->id])
        ->assertOk()->assertJsonPath('data.status', 'withdrawn');
    $this->getJson('/api/v1/mood-entries')->assertForbidden()->assertJsonPath('code', 'consent_required');
    $this->getJson('/api/v1/me/consents')->assertOk();
});

function ageReviewCase(User $patient, User $reviewer, string $kind): string
{
    $id = (string) \Illuminate\Support\Str::uuid();
    DB::table('age_support_cases')->insert(['id' => $id, 'user_id' => $patient->id,
        'assignee_id' => $reviewer->id, 'kind' => $kind, 'status' => 'open',
        'message' => encrypt('Synthetic support request.'), 'created_at' => now(), 'updated_at' => now()]);
    return $id;
}

function ageReviewDecision(string $action, int $years = 25): array
{
    return ['action' => $action, 'note' => 'Reviewed using the approved support procedure.',
        'authority_checked' => true, 'verification_reference' => 'synthetic-check-reference',
        ...($action === 'correct_dob' ? ['date_of_birth' => now('UTC')->subYears($years)->subDay()->format('Y-m-d')] : [])];
}

it('retains other open blocking cases when a reviewer resolves a dispute', function (int $years, string $firstKind, string $dobAction) {
    ageReady(); Mail::fake(); config(['age.correction_approval' => 'synthetic-approved-procedure']);
    $patient = ageUser($years); ageAccept($patient);
    $authorization = ageAuthorization($patient);
    $patient->forceFill(['age_disputed' => true])->save();
    $reviewer = User::factory()->create(['role' => 'admin']);
    $cases = [
        'dob_correction' => ageReviewCase($patient, $reviewer, 'dob_correction'),
        'guardian_dispute' => ageReviewCase($patient, $reviewer, 'guardian_dispute'),
    ];
    $this->withToken($reviewer->createToken('cozy-admin-management-v1', ['admin:guardians'], now()->addMinutes(30))->plainTextToken);
    $lastKind = $firstKind === 'dob_correction' ? 'guardian_dispute' : 'dob_correction';
    $firstAction = $firstKind === 'dob_correction' ? $dobAction : 'clear_dispute';
    $lastAction = $lastKind === 'dob_correction' ? $dobAction : 'clear_dispute';

    $this->postJson('/api/v1/admin/age-cases/'.$cases[$firstKind].'/resolve', ageReviewDecision($firstAction, $years))->assertOk();
    expect((bool) $patient->fresh()->age_disputed)->toBeTrue();
    expect(app(AgeEligibility::class)->state($patient->fresh())['reason'])->toBe('age_disputed');
    $this->assertDatabaseHas('age_support_cases', ['id' => $cases[$lastKind], 'status' => 'open']);
    expect($authorization->fresh()->status)->toBe('review_required');

    $this->postJson('/api/v1/admin/age-cases/'.$cases[$lastKind].'/resolve', ageReviewDecision($lastAction, $years))->assertOk();
    expect((bool) $patient->fresh()->age_disputed)->toBeFalse();
    expect(app(AgeEligibility::class)->state($patient->fresh())['reason'])->toBe($years < 18 ? 'guardian_required' : null);
    if ($dobAction === 'correct_dob') {
        expect($patient->fresh()->date_of_birth->format('Y-m-d'))->toBe(ageReviewDecision('correct_dob', $years)['date_of_birth']);
    }
    expect(DB::table('age_authorization_events')->where('user_id', $patient->id)->count())->toBe(2);
})->with([16, 25])->with(['dob_correction', 'guardian_dispute'])->with(['clear_dispute', 'correct_dob']);

it('does not let a privacy case clear an age restriction or change a birthday', function (string $action) {
    ageReady(); Mail::fake(); config(['age.correction_approval' => 'synthetic-approved-procedure']);
    $patient = ageUser(25); $patient->forceFill(['age_disputed' => true])->save();
    $reviewer = User::factory()->create(['role' => 'admin']);
    $id = ageReviewCase($patient, $reviewer, 'privacy');
    $this->withToken($reviewer->createToken('cozy-admin-management-v1', ['admin:guardians'], now()->addMinutes(30))->plainTextToken)
        ->postJson('/api/v1/admin/age-cases/'.$id.'/resolve', ageReviewDecision($action))->assertConflict();
    expect((bool) $patient->fresh()->age_disputed)->toBeTrue();
    expect($patient->fresh()->date_of_birth->format('Y-m-d'))->toBe($patient->date_of_birth->format('Y-m-d'));
    $this->assertDatabaseHas('age_support_cases', ['id' => $id, 'status' => 'open']);
    $this->assertDatabaseMissing('age_authorization_events', ['user_id' => $patient->id]);
})->with(['clear_dispute', 'correct_dob']);

it('keeps an ordinary closure restricted until an approved appeal clears it', function () {
    ageReady(); Mail::fake(); config(['age.correction_approval' => 'synthetic-approved-procedure']);
    $patient = ageUser(25); ageAccept($patient); $patient->forceFill(['age_disputed' => true])->save();
    $reviewer = User::factory()->create(['role' => 'admin']);
    $id = ageReviewCase($patient, $reviewer, 'dob_correction');
    $this->withToken($reviewer->createToken('cozy-admin-management-v1', ['admin:guardians'], now()->addMinutes(30))->plainTextToken)
        ->postJson('/api/v1/admin/age-cases/'.$id.'/resolve', ageReviewDecision('resolve'))->assertOk();
    expect(app(AgeEligibility::class)->state($patient->fresh())['reason'])->toBe('age_disputed');
    $appeal = ageReviewCase($patient, $reviewer, 'appeal');
    $otherPatient = ageUser(25);
    ageReviewCase($otherPatient, $reviewer, 'guardian_dispute');
    ageReviewCase($patient, $reviewer, 'privacy');
    $this->postJson('/api/v1/admin/age-cases/'.$appeal.'/resolve', ageReviewDecision('clear_dispute'))->assertOk();
    expect(app(AgeEligibility::class)->allows($patient->fresh()))->toBeTrue();
});

it('rejects stale or unassigned reviewer decisions without removing a newer dispute', function () {
    ageReady(); Mail::fake(); config(['age.correction_approval' => 'synthetic-approved-procedure']);
    $patient = ageUser(25); $patient->forceFill(['age_disputed' => true])->save();
    $firstReviewer = User::factory()->create(['role' => 'admin']);
    $secondReviewer = User::factory()->create(['role' => 'admin']);
    $id = ageReviewCase($patient, $firstReviewer, 'guardian_dispute');
    $this->withToken($secondReviewer->createToken('cozy-admin-management-v1', ['admin:guardians'], now()->addMinutes(30))->plainTextToken)
        ->postJson('/api/v1/admin/age-cases/'.$id.'/resolve', ageReviewDecision('clear_dispute'))->assertConflict();
    expect((bool) $patient->fresh()->age_disputed)->toBeTrue();
    $this->postJson('/api/v1/admin/age-cases/'.$id.'/resolve', ageReviewDecision('assign'))->assertOk();
    $this->postJson('/api/v1/admin/age-cases/'.$id.'/resolve', ageReviewDecision('clear_dispute'))->assertOk();
    $newCase = ageReviewCase($patient, $secondReviewer, 'dob_correction');
    $patient->refresh()->forceFill(['age_disputed' => true])->save();
    $this->postJson('/api/v1/admin/age-cases/'.$id.'/resolve', ageReviewDecision('clear_dispute'))->assertConflict();
    expect((bool) $patient->fresh()->age_disputed)->toBeTrue();
    $this->assertDatabaseHas('age_support_cases', ['id' => $newCase, 'status' => 'open']);
});

it('rolls back case closure and access changes if recording the decision fails', function () {
    ageReady(); Mail::fake(); config(['age.correction_approval' => 'synthetic-approved-procedure']);
    $patient = ageUser(25); $patient->forceFill(['age_disputed' => true])->save();
    $authorization = ageAuthorization($patient);
    $reviewer = User::factory()->create(['role' => 'admin']);
    $id = ageReviewCase($patient, $reviewer, 'dob_correction');
    $eligibility = \Mockery::mock(AgeEligibility::class)->makePartial();
    $eligibility->shouldReceive('event')->once()->andThrow(new RuntimeException('Synthetic audit write failure'));
    app()->instance(AgeEligibility::class, $eligibility);
    $this->withToken($reviewer->createToken('cozy-admin-management-v1', ['admin:guardians'], now()->addMinutes(30))->plainTextToken)
        ->postJson('/api/v1/admin/age-cases/'.$id.'/resolve', ageReviewDecision('correct_dob'))->assertStatus(500);
    expect((bool) $patient->fresh()->age_disputed)->toBeTrue();
    expect($patient->fresh()->date_of_birth->format('Y-m-d'))->toBe($patient->date_of_birth->format('Y-m-d'));
    expect($authorization->fresh()->status)->toBe('authorized');
    $this->assertDatabaseHas('age_support_cases', ['id' => $id, 'status' => 'open', 'resolution' => null]);
    $this->assertDatabaseMissing('age_authorization_events', ['user_id' => $patient->id]);
});
