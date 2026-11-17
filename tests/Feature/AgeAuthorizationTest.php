<?php

use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Mail};
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('registers without custom age or guardian fields even with stale feature flags', function () {
    config(['age.enabled' => true, 'age.invitations_enabled' => true]);
    Mail::fake();
    $this->postJson('/api/v1/auth/register', ['name' => 'Store User', 'email' => 'store@example.com',
        'password' => 'Fixture7!Strong', 'password_confirmation' => 'Fixture7!Strong'])
        ->assertSuccessful();
    $this->assertDatabaseHas('users', ['email' => 'store@example.com', 'date_of_birth' => null]);
    $this->assertDatabaseCount('guardian_authorizations', 0);
    $this->assertDatabaseCount('age_authorization_events', 0);
});

it('returns a nonblocking compatibility response without exposing historical age fields', function () {
    Sanctum::actingAs(User::factory()->create(['date_of_birth' => '2010-01-01']));
    config(['age.enabled' => true]);
    $this->getJson('/api/v1/me/eligibility')->assertOk()->assertJsonPath('data.enforced', false)
        ->assertJsonPath('data.allowed', true)->assertJsonPath('data.retired', true);
    $this->getJson('/api/v1/config')->assertOk()->assertJsonPath('age.enforced', false);
});

it('retires age and guardian writes without consuming tokens creating records or sending mail', function (string $method, string $uri) {
    Mail::fake();
    Sanctum::actingAs(User::factory()->create());
    $this->json($method, '/api/v1/'.$uri, ['token' => str_repeat('a', 64), 'decision' => 'authorize'])
        ->assertStatus(410)->assertJsonPath('code', 'age_flow_retired');
    $this->assertDatabaseCount('guardian_authorizations', 0);
    $this->assertDatabaseCount('age_authorization_events', 0);
    $this->assertDatabaseCount('age_support_cases', 0);
    Mail::assertNothingSent(); Mail::assertNothingQueued();
})->with([
    ['POST', 'me/age'], ['PUT', 'me/residence'], ['POST', 'me/guardian'],
    ['DELETE', 'me/guardian'], ['POST', 'me/age-support'], ['POST', 'guardian/confirm'],
    ['POST', 'guardian/review'], ['POST', 'guardian/decision'], ['POST', 'guardian/withdrawal'],
]);

it('still blocks private records until ordinary document consent is accepted', function () {
    config(['age.enabled' => true]);
    app(SettingsService::class)->set('consent.enforce_blocking', true);
    $documents = config('consent.documents');
    foreach (['tos', 'privacy_policy', 'health_data'] as $type) $documents[$type] = [
        'title' => $type, 'version' => 'retirement-1', 'required' => true,
        'published' => true, 'content' => 'Synthetic approved test document.',
    ];
    app(SettingsService::class)->set('consent.documents', $documents);
    $user = User::factory()->create(); Sanctum::actingAs($user);
    $this->getJson('/api/v1/mood-entries')->assertForbidden()->assertJsonPath('code', 'consent_required');
    foreach ($documents as $type => $doc) if ($doc['published'] && $doc['required']) DB::table('user_consents')->insert([
        'user_id' => $user->id, 'document_type' => $type, 'version' => $doc['version'],
        'status' => 'accepted', 'recorded_at' => now(), 'accepted_at' => now(),
    ]);
    $this->getJson('/api/v1/mood-entries')->assertOk();
    $this->assertDatabaseCount('guardian_authorizations', 0);
});
