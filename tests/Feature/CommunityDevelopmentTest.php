<?php

use App\Models\{FeatureFlag, User};
use App\Services\CommunityAvailability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['community.release_ready' => false, 'community.guidelines_version' => 'dev-fixture-1',
        'community.development_testing' => true,
        'community.railway_project_id' => CommunityAvailability::DEVELOPMENT_PROJECT,
        'community.railway_environment_id' => CommunityAvailability::DEVELOPMENT_ENVIRONMENT,
        'app.url' => 'https://api-dev.cozyhealth.org']);
    FeatureFlag::updateOrCreate(['key' => 'community'], ['enabled' => true]);
    $documents = config('consent.documents');
    $documents['ugc_guidelines'] = [...$documents['ugc_guidelines'], 'published' => true,
        'version' => 'dev-fixture-1', 'content' => 'Synthetic development guidelines.'];
    config(['consent.documents' => $documents]);
});

it('enables real authenticated community routes and advertises the same effective flag in development', function () {
    $this->getJson('/api/v1/community/posts')->assertUnauthorized();
    Sanctum::actingAs(User::factory()->create());
    $this->getJson('/api/v1/community/posts')->assertOk();
    $this->getJson('/api/v1/config')->assertOk()->assertJsonPath('flags.community', true);
    expect(config('community.release_ready'))->toBeFalse();
});

it('fails closed when development identity or configuration does not match', function (string $setting, mixed $value) {
    config([$setting => $value]);
    Sanctum::actingAs(User::factory()->create());
    expect(app(CommunityAvailability::class)->developmentTesting())->toBeFalse();
    $this->getJson('/api/v1/community/posts')->assertStatus(503);
    $this->getJson('/api/v1/config')->assertOk()->assertJsonPath('flags.community', false);
})->with([
    ['community.development_testing', false],
    ['community.railway_project_id', null],
    ['community.railway_project_id', 'another-project'],
    ['community.railway_environment_id', null],
    ['community.railway_environment_id', '9ffd3fd1-ae52-4cc7-903e-35b529a151ae'],
    ['community.railway_environment_id', 'another-environment'],
    ['app.url', 'https://api.cozyhealth.org'],
    ['app.url', 'http://api-dev.cozyhealth.org'],
    ['app.url', 'https://api-dev.cozyhealth.org.evil.example'],
    ['app.url', 'https://user@api-dev.cozyhealth.org'],
    ['app.url', 'https://api-dev.cozyhealth.org?test=1'],
    ['app.url', 'https://api-dev.cozyhealth.org#fragment'],
    ['app.url', 'https://api-dev.cozyhealth.org:444'],
    ['app.url', 'not a URL'],
]);

it('still requires guidelines and the persisted feature switch', function () {
    Sanctum::actingAs(User::factory()->create());
    config(['community.guidelines_version' => null]);
    $this->getJson('/api/v1/community/posts')->assertStatus(503);
    config(['community.guidelines_version' => 'dev-fixture-1']);
    FeatureFlag::where('key', 'community')->firstOrFail()->update(['enabled' => false]);
    $this->getJson('/api/v1/community/posts')->assertStatus(503);
    $this->getJson('/api/v1/config')->assertOk()->assertJsonPath('flags.community', false);
});

it('preserves the existing explicit public-readiness path without granting it through the development switch', function () {
    config(['community.railway_environment_id' => 'other-environment', 'community.release_ready' => true]);
    expect(app(CommunityAvailability::class)->developmentTesting())->toBeFalse();
    expect(app(CommunityAvailability::class)->enabled())->toBeTrue();
});

it('does not let a cached enabled flag override a revoked development identity', function () {
    $this->getJson('/api/v1/config')->assertOk()->assertJsonPath('flags.community', true);
    config(['community.railway_environment_id' => 'other-environment']);
    $this->getJson('/api/v1/config')->assertOk()->assertJsonPath('flags.community', false);
});

it('previews without changing the feature switch or creating fixtures', function () {
    FeatureFlag::where('key', 'community')->firstOrFail()->update(['enabled' => false]);
    $this->artisan('community:development')->assertSuccessful();
    expect(FeatureFlag::where('key', 'community')->value('enabled'))->toBeFalse();
    $this->assertDatabaseCount('users', 0);
    $this->assertDatabaseCount('community_groups', 0);
});

it('creates labelled fixtures idempotently without mail devices or permanent tokens', function () {
    \Illuminate\Support\Facades\Mail::fake();
    FeatureFlag::where('key', 'community')->firstOrFail()->update(['enabled' => false]);
    foreach ([1, 2] as $_) $this->artisan('community:development', ['--apply' => true, '--fixtures' => true])->assertSuccessful();
    $this->assertDatabaseCount('users', 3);
    $this->assertDatabaseCount('user_profiles', 3);
    $this->assertDatabaseCount('community_groups', 1);
    $this->assertDatabaseCount('personal_access_tokens', 0);
    $this->assertDatabaseCount('push_devices', 0);
    expect(FeatureFlag::where('key', 'community')->value('enabled'))->toBeTrue();
    expect(config('community.release_ready'))->toBeFalse();
    \Illuminate\Support\Facades\Mail::assertNothingSent();
    \Illuminate\Support\Facades\Mail::assertNothingQueued();
});

it('refuses fixture creation outside the approved environment even when public readiness is set', function () {
    config(['community.railway_environment_id' => 'other-environment', 'community.release_ready' => true]);
    FeatureFlag::where('key', 'community')->firstOrFail()->update(['enabled' => false]);
    $this->artisan('community:development', ['--apply' => true, '--fixtures' => true])->assertFailed();
    $this->assertDatabaseCount('users', 0);
    $this->assertDatabaseCount('community_groups', 0);
    expect(FeatureFlag::where('key', 'community')->value('enabled'))->toBeFalse();
});

it('refuses activation when the client guidelines and configured posting version differ', function () {
    config(['community.guidelines_version' => 'different-version']);
    FeatureFlag::where('key', 'community')->firstOrFail()->update(['enabled' => false]);
    $this->artisan('community:development', ['--apply' => true])->assertFailed();
    expect(FeatureFlag::where('key', 'community')->value('enabled'))->toBeFalse();
});

it('does not take over an existing non-fixture account', function () {
    User::factory()->create(['email' => 'community-dev-author@example.test', 'name' => 'Existing account']);
    FeatureFlag::where('key', 'community')->firstOrFail()->update(['enabled' => false]);
    $this->artisan('community:development', ['--apply' => true, '--fixtures' => true])->assertFailed();
    $this->assertDatabaseCount('users', 1);
    expect(User::first()->name)->toBe('Existing account');
    expect(FeatureFlag::where('key', 'community')->value('enabled'))->toBeFalse();
});
