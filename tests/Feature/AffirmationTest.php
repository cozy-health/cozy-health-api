<?php

use App\Models\Affirmation;
use App\Models\User;
use Database\Seeders\AffirmationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-08 12:00:00', 'UTC'));
    Cache::flush();
});

afterEach(function () {
    Carbon::setTestNow();
});

test('today_returns_an_affirmation', function () {
    $affirmation = Affirmation::create(['body' => 'Rest is not something you have to earn.']);
    $this->actingAs(User::factory()->create(['timezone' => 'UTC']), 'sanctum')
        ->getJson('/api/v1/affirmations/today')->assertOk()->assertExactJson([
            'success' => true, 'message' => "Today's affirmation retrieved.",
            'data' => ['body' => $affirmation->body, 'date' => '2026-10-08'],
        ]);
});

test('today_returns_same_affirmation_for_same_user_same_day', function () {
    $this->seed(AffirmationSeeder::class);
    $this->actingAs(User::factory()->create(['timezone' => 'UTC']), 'sanctum');
    $first = $this->getJson('/api/v1/affirmations/today')->assertOk()->json('data');
    Carbon::setTestNow(Carbon::parse('2026-10-08 23:59:59', 'UTC'));
    $this->getJson('/api/v1/affirmations/today')->assertOk()->assertJsonPath('data', $first);
});

test('today_returns_different_affirmation_next_day', function () {
    $this->seed(AffirmationSeeder::class);
    $this->actingAs(User::factory()->create(['timezone' => 'UTC']), 'sanctum');
    // Verify every adjacent day through a full rotation, not one fortunate hash pair.
    $previous = null;
    for ($day = 0; $day < 31; $day++) {
        Carbon::setTestNow(Carbon::parse('2026-10-08 12:00:00', 'UTC')->addDays($day));
        $body = $this->getJson('/api/v1/affirmations/today')->assertOk()->json('data.body');
        if ($previous !== null) expect($body)->not->toBe($previous);
        $previous = $body;
    }
});

test('today_returns_empty_when_no_affirmations', function () {
    $this->actingAs(User::factory()->create(), 'sanctum')->getJson('/api/v1/affirmations/today')
        ->assertOk()->assertExactJson(['success' => true, 'message' => 'No affirmations available.', 'data' => null]);
});

test('today_respects_user_timezone_for_date_boundary', function () {
    $this->seed(AffirmationSeeder::class);
    $this->actingAs(User::factory()->create(['timezone' => 'Pacific/Auckland']), 'sanctum');
    Carbon::setTestNow(Carbon::parse('2026-10-08 10:59:59', 'UTC'));
    $before = $this->getJson('/api/v1/affirmations/today')->assertOk()->assertJsonPath('data.date', '2026-10-08')->json('data.body');
    Carbon::setTestNow(Carbon::parse('2026-10-08 11:00:00', 'UTC'));
    $after = $this->getJson('/api/v1/affirmations/today')->assertOk()->assertJsonPath('data.date', '2026-10-09')->json('data.body');
    expect($after)->not->toBe($before);
});

test('today_requires_auth', function () {
    $this->getJson('/api/v1/affirmations/today')->assertUnauthorized();
});

test('today_is_throttled', function () {
    $this->actingAs(User::factory()->create(), 'sanctum');
    for ($attempt = 0; $attempt < 60; $attempt++) $this->getJson('/api/v1/affirmations/today')->assertOk();
    $this->getJson('/api/v1/affirmations/today')->assertStatus(429);
    Carbon::setTestNow(now()->addSeconds(61));
    $this->getJson('/api/v1/affirmations/today')->assertOk();
});

test('today_excludes_inactive_affirmations', function () {
    Affirmation::create(['body' => 'Inactive copy.', 'is_active' => false]);
    $this->actingAs(User::factory()->create(), 'sanctum')->getJson('/api/v1/affirmations/today')->assertOk()->assertJsonPath('data', null);
    Affirmation::create(['body' => 'Active copy.']);
    $this->getJson('/api/v1/affirmations/today')->assertOk()->assertJsonPath('data.body', 'Active copy.');
});

test('affirmation_seeder_is_repeatable_and_preserves_disabled_copy', function () {
    $this->seed(AffirmationSeeder::class);
    expect(Affirmation::count())->toBe(30);
    Affirmation::first()->update(['is_active' => false]);
    $this->seed(AffirmationSeeder::class);
    expect(Affirmation::count())->toBe(30)->and(Affirmation::where('is_active', true)->count())->toBe(29);
});

test('today_keeps_same_affirmation_across_repeated_dst_hour', function () {
    $this->seed(AffirmationSeeder::class);
    $this->actingAs(User::factory()->create(['timezone' => 'America/New_York']), 'sanctum');
    Carbon::setTestNow(Carbon::parse('2026-11-01 05:30:00', 'UTC'));
    $first = $this->getJson('/api/v1/affirmations/today')->assertOk()->assertJsonPath('data.date', '2026-11-01')->json('data');
    Carbon::setTestNow(Carbon::parse('2026-11-01 06:30:00', 'UTC'));
    $this->getJson('/api/v1/affirmations/today')->assertOk()->assertJsonPath('data', $first);
});
