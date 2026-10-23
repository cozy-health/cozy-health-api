<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'age.enabled' => false,
        'age.invitations_enabled' => false,
        'age.health_data_ready' => false,
        'age.approved_countries' => [],
    ]);
});

it('verifies a migrated deployment without modifying records', function () {
    $this->artisan('guardians:release-check', ['--schema' => true])
        ->expectsOutput('PASS: guardian_authorizations table exists')
        ->assertSuccessful();

    $this->assertDatabaseCount('guardian_authorizations', 0);
    $this->assertDatabaseCount('age_authorization_events', 0);
    $this->assertDatabaseCount('age_support_cases', 0);
});

it('rejects each accidentally enabled feature independently', function (string $setting, mixed $value) {
    config([$setting => $value]);
    $this->artisan('guardians:release-check')->assertFailed();
})->with([
    'enforcement' => ['age.enabled', true],
    'invitations' => ['age.invitations_enabled', true],
    'minor readiness' => ['age.health_data_ready', true],
    'countries' => ['age.approved_countries', ['NG']],
]);

it('rejects a missing guardian migration', function () {
    Schema::drop('age_support_cases');
    $this->artisan('guardians:release-check', ['--schema' => true])
        ->expectsOutput('FAIL: age_support_cases table exists')
        ->assertFailed();
});
