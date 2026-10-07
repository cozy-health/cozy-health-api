<?php

use App\Http\Controllers\Api\V1\JournalController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

afterEach(function () {
    Carbon::setTestNow();
});

function timezoneTestUser(array $attributes = []): User
{
    return User::create([
        'name' => 'Timezone User',
        'email' => 'timezone@example.com',
        'password' => 'password123',
        ...$attributes,
    ]);
}

it('defaults to UTC on registration and me', function () {
    Mail::fake();
    $response = $this->postJson('/api/v1/auth/register', [
        'name' => 'Timezone User', 'email' => 'timezone@example.com',
        'password' => 'password123', 'password_confirmation' => 'password123',
    ])->assertCreated()->assertJsonPath('data.user.timezone', 'UTC');

    $this->withToken($response->json('data.token'))->getJson('/api/v1/me')
        ->assertOk()->assertJsonPath('data.timezone', 'UTC');
    $this->assertDatabaseHas('users', ['email' => 'timezone@example.com', 'timezone' => 'UTC']);
});

it('updates timezone through me and preserves omitted timezone', function () {
    $user = timezoneTestUser();
    $this->actingAs($user, 'sanctum')->patchJson('/api/v1/me', ['timezone' => 'Africa/Lagos'])
        ->assertOk()->assertJsonPath('data.timezone', 'Africa/Lagos');
    $this->patchJson('/api/v1/me', ['name' => 'Updated'])
        ->assertOk()->assertJsonPath('data.timezone', 'Africa/Lagos');
    $this->assertDatabaseHas('users', ['id' => $user->id, 'timezone' => 'Africa/Lagos']);
});

it('updates timezone through the live user profile without another column', function () {
    $user = timezoneTestUser();
    $this->actingAs($user, 'sanctum')->patchJson('/api/v1/user/profile', ['timezone' => 'Asia/Tokyo'])
        ->assertOk()->assertJsonPath('data.timezone', 'Asia/Tokyo')
        ->assertJsonPath('message', 'Profile updated.');
    $this->getJson('/api/v1/user/profile')->assertOk()->assertJsonPath('data.timezone', 'Asia/Tokyo');
    $this->assertDatabaseHas('users', ['id' => $user->id, 'timezone' => 'Asia/Tokyo']);
});

it('rejects invalid and null timezones on both update routes', function () {
    $user = timezoneTestUser();
    $this->actingAs($user, 'sanctum');
    foreach (['/api/v1/me', '/api/v1/user/profile'] as $route) {
        foreach (['Not/A/Timezone', null] as $timezone) {
            $this->patchJson($route, ['timezone' => $timezone])
                ->assertUnprocessable()->assertJsonValidationErrors('timezone');
        }
    }
    expect($user->fresh()->timezone)->toBe('UTC');
});

it('includes stored timezone on login', function () {
    timezoneTestUser(['timezone' => 'Africa/Lagos']);
    $this->postJson('/api/v1/auth/login', ['email' => 'timezone@example.com', 'password' => 'password123'])
        ->assertOk()->assertJsonPath('data.user.timezone', 'Africa/Lagos');
});

it('keeps UTC and legacy null clocks and serialization unchanged', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-07 22:00:00', 'UTC'));
    foreach (['UTC', null] as $timezone) {
        $user = new User(['timezone' => $timezone]);
        expect($user->localNow()->toIso8601String())->toBe('2026-10-07T22:00:00+00:00');
        expect($user->localToday()->toDateString())->toBe('2026-10-07');
        expect($user->toArray()['timezone'])->toBe('UTC');
    }
});

it('recognizes two consecutive Auckland local days within one UTC date', function () {
    $user = new User(['timezone' => 'Pacific/Auckland']);
    Carbon::setTestNow(Carbon::parse('2026-10-07 10:00:00', 'UTC'));
    $first = $user->localToday(); // October 7, 23:00 local.
    Carbon::setTestNow(Carbon::parse('2026-10-07 12:00:00', 'UTC'));
    $second = $user->localToday(); // October 8, 01:00 local.
    expect($first->toDateString())->toBe('2026-10-07');
    expect($second->toDateString())->toBe('2026-10-08');
    expect($first->copy()->addDay()->equalTo($second))->toBeTrue();
});

it('derives Tokyo today and UTC day boundaries from the local clock', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-07 22:00:00', 'UTC'));
    $user = new User(['timezone' => 'Asia/Tokyo']);
    expect($user->localToday()->toDateString())->toBe('2026-10-08');
    expect($user->localToday()->utc()->toDateTimeString())->toBe('2026-10-07 15:00:00');
    expect($user->localToday()->addDay()->utc()->toDateTimeString())->toBe('2026-10-08 15:00:00');
});

it('handles a DST transition as a 25 hour local day', function () {
    Carbon::setTestNow(Carbon::parse('2026-11-01 18:00:00', 'UTC'));
    $user = new User(['timezone' => 'America/New_York']);
    $start = $user->localToday()->utc();
    $end = $user->localToday()->addDay()->utc();
    expect($end->getTimestamp() - $start->getTimestamp())->toBe(25 * 3600);
});

it('defaults journal entry date to the local day and preserves explicit dates', function () {
    // This existing controller is unrouted in production; register only for this test.
    Route::post('/timezone-test/journals', [JournalController::class, 'store'])->middleware('auth:sanctum');
    Carbon::setTestNow(Carbon::parse('2026-10-07 22:00:00', 'UTC'));
    $user = timezoneTestUser(['timezone' => 'Asia/Tokyo']);
    $this->actingAs($user, 'sanctum')->postJson('/timezone-test/journals', ['content' => 'Local day'])
        ->assertCreated();
    $this->assertDatabaseHas('journals', ['user_id' => $user->id, 'entry_date' => '2026-10-08 00:00:00']);
    $this->postJson('/timezone-test/journals', ['content' => 'Explicit day', 'entry_date' => '2026-10-01'])
        ->assertCreated();
    $this->assertDatabaseHas('journals', ['user_id' => $user->id, 'entry_date' => '2026-10-01 00:00:00']);
});

it('validates date of birth against the user local date', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-07 22:00:00', 'UTC'));
    $user = timezoneTestUser(['timezone' => 'Asia/Tokyo']);
    $this->actingAs($user, 'sanctum')->patchJson('/api/v1/me', ['date_of_birth' => '2026-10-07'])
        ->assertOk();
    $this->patchJson('/api/v1/me', ['date_of_birth' => '2026-10-08'])
        ->assertUnprocessable()->assertJsonValidationErrors('date_of_birth');
});
