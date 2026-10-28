<?php

use App\Models\MoodEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-08 12:00:00', 'UTC'));
    Cache::flush();
});

afterEach(function () {
    Carbon::setTestNow();
});

function dashboardUser(string $timezone = 'UTC'): User
{
    return User::factory()->create(['timezone' => $timezone]);
}

function dashboardMood(User $user, string $time, array $attributes = []): MoodEntry
{
    return MoodEntry::create(array_merge([
        'id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'mood' => 'good',
        'intensity' => 6,
        'client_created_at' => Carbon::parse($time, 'UTC'),
    ], $attributes));
}

it('dashboard returns empty state for a new user', function () {
    $this->actingAs(dashboardUser(), 'sanctum')->getJson('/api/v1/dashboard')
        ->assertOk()->assertExactJson([
            'success' => true,
            'message' => 'Dashboard retrieved.',
            'data' => [
                'today_mood' => null,
                'streak_days' => 0,
                'weekly_moods' => [],
                'recent_entries' => [],
                'timezone' => 'UTC',
            ],
        ]);
});

it('dashboard returns the latest today mood with only projected fields', function () {
    $user = dashboardUser();
    dashboardMood($user, '2026-10-08 08:00:00');
    $latest = dashboardMood($user, '2026-10-08 11:00:00', [
        'mood' => 'sad', 'intensity' => 3, 'note' => 'Private content', 'is_crisis_flagged' => true,
    ]);
    $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/dashboard')
        ->assertOk()->assertJsonPath('data.today_mood.id', $latest->id)
        ->assertJsonPath('data.today_mood.mood', 'sad')
        ->assertJsonPath('data.today_mood.intensity', 3);
    expect(array_keys($response->json('data.today_mood')))
        ->toEqualCanonicalizing(['id', 'mood', 'intensity', 'created_at', 'client_created_at']);
    expect($response->getContent())->not->toContain('Private content')
        ->not->toContain('is_crisis_flagged');
});

it('dashboard calculates a streak of one despite multiple moods today', function () {
    $user = dashboardUser();
    dashboardMood($user, '2026-10-08 08:00:00');
    dashboardMood($user, '2026-10-08 10:00:00');
    $this->actingAs($user, 'sanctum')->getJson('/api/v1/dashboard')
        ->assertOk()->assertJsonPath('data.streak_days', 1);
});

it('dashboard calculates a streak of seven', function () {
    $user = dashboardUser();
    for ($day = 0; $day < 7; $day++) {
        dashboardMood($user, now()->subDays($day)->toDateTimeString());
    }
    $this->actingAs($user, 'sanctum')->getJson('/api/v1/dashboard')
        ->assertOk()->assertJsonPath('data.streak_days', 7);
});

it('dashboard streak breaks at the first gap', function () {
    $user = dashboardUser();
    foreach ([0, 1, 3, 4] as $day) {
        dashboardMood($user, now()->subDays($day)->toDateTimeString());
    }
    $this->actingAs($user, 'sanctum')->getJson('/api/v1/dashboard')
        ->assertOk()->assertJsonPath('data.streak_days', 2);
});

it('dashboard streak continues from yesterday when today is empty', function () {
    $user = dashboardUser();
    foreach ([1, 2, 3] as $day) {
        dashboardMood($user, now()->subDays($day)->toDateTimeString());
    }
    $this->actingAs($user, 'sanctum')->getJson('/api/v1/dashboard')
        ->assertOk()->assertJsonPath('data.today_mood', null)->assertJsonPath('data.streak_days', 3);
});

it('dashboard streak is zero when both today and yesterday are empty', function () {
    $user = dashboardUser();
    dashboardMood($user, '2026-10-06 12:00:00');
    $this->actingAs($user, 'sanctum')->getJson('/api/v1/dashboard')
        ->assertOk()->assertJsonPath('data.streak_days', 0);
});

it('dashboard uses Auckland dates across UTC midnight', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-08 12:00:00', 'UTC')); // October 9 local.
    $user = dashboardUser('Pacific/Auckland');
    dashboardMood($user, '2026-10-08 10:30:00'); // October 8, 23:30 local.
    $today = dashboardMood($user, '2026-10-08 11:30:00'); // October 9, 00:30 local.
    $this->actingAs($user, 'sanctum')->getJson('/api/v1/dashboard')
        ->assertOk()->assertJsonPath('data.timezone', 'Pacific/Auckland')
        ->assertJsonPath('data.today_mood.id', $today->id)->assertJsonPath('data.streak_days', 2)
        ->assertJsonPath('data.weekly_moods.0.day', '2026-10-08')
        ->assertJsonPath('data.weekly_moods.1.day', '2026-10-09');
});

it('dashboard honors a timezone west of UTC near midnight', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-08 02:00:00', 'UTC')); // October 7 in LA.
    $user = dashboardUser('America/Los_Angeles');
    $today = dashboardMood($user, '2026-10-08 01:00:00');
    $this->actingAs($user, 'sanctum')->getJson('/api/v1/dashboard')
        ->assertOk()->assertJsonPath('data.today_mood.id', $today->id)
        ->assertJsonPath('data.weekly_moods.0.day', '2026-10-07');
});

it('dashboard weekly returns seven occupied days with counts and averages', function () {
    $user = dashboardUser();
    for ($day = 0; $day < 7; $day++) {
        dashboardMood($user, now()->subDays($day)->toDateTimeString(), ['intensity' => 2]);
        dashboardMood($user, now()->subDays($day)->subHour()->toDateTimeString(), ['intensity' => 7]);
    }
    dashboardMood($user, '2026-10-01 23:59:59'); // Before the inclusive window.
    $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/dashboard')
        ->assertOk()->assertJsonCount(7, 'data.weekly_moods')
        ->assertJsonPath('data.weekly_moods.0.day', '2026-10-02')
        ->assertJsonPath('data.weekly_moods.6.day', '2026-10-08');
    foreach ($response->json('data.weekly_moods') as $day) {
        expect($day['count'])->toBe(2)->and($day['avg_intensity'])->toBe(4.5);
    }
});

it('dashboard recent returns only five entries ordered by event time', function () {
    $user = dashboardUser();
    $entries = [];
    for ($day = 0; $day < 8; $day++) {
        $entries[] = dashboardMood($user, now()->subDays($day)->toDateTimeString());
    }
    $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/dashboard')
        ->assertOk()->assertJsonCount(5, 'data.recent_entries');
    expect(array_column($response->json('data.recent_entries'), 'id'))
        ->toBe(array_map(fn ($entry) => $entry->id, array_slice($entries, 0, 5)));
});

it('dashboard requires authentication', function () {
    $this->getJson('/api/v1/dashboard')->assertUnauthorized();
});

it('dashboard is throttled at sixty requests per minute', function () {
    $this->actingAs(dashboardUser(), 'sanctum');
    for ($attempt = 0; $attempt < 60; $attempt++) {
        $this->getJson('/api/v1/dashboard')->assertOk();
    }
    $this->getJson('/api/v1/dashboard')->assertStatus(429);
    Carbon::setTestNow(now()->addSeconds(61));
    $this->getJson('/api/v1/dashboard')->assertOk();
});

it('dashboard excludes another users records even with a supplied user id', function () {
    $owner = dashboardUser();
    $other = dashboardUser();
    dashboardMood($other, '2026-10-08 10:00:00');
    $this->actingAs($owner, 'sanctum')->getJson('/api/v1/dashboard?user_id='.$other->id)
        ->assertOk()->assertJsonPath('data.today_mood', null)->assertJsonPath('data.streak_days', 0)
        ->assertJsonPath('data.weekly_moods', [])->assertJsonPath('data.recent_entries', []);
});

it('dashboard ignores sync deleted records in every metric', function () {
    $user = dashboardUser();
    dashboardMood($user, '2026-10-08 10:00:00', ['is_deleted' => true]);
    $this->actingAs($user, 'sanctum')->getJson('/api/v1/dashboard')
        ->assertOk()->assertJsonPath('data.today_mood', null)->assertJsonPath('data.streak_days', 0)
        ->assertJsonPath('data.weekly_moods', [])->assertJsonPath('data.recent_entries', []);
});

it('dashboard falls back to server creation time only for null client timestamps', function () {
    $user = dashboardUser();
    $legacy = dashboardMood($user, '2026-10-08 09:00:00', ['client_created_at' => null]);
    // Uploaded today but actually recorded long ago: never count upload as a mood day.
    dashboardMood($user, '2026-09-01 12:00:00');
    $this->actingAs($user, 'sanctum')->getJson('/api/v1/dashboard')
        ->assertOk()->assertJsonPath('data.today_mood.id', $legacy->id)
        ->assertJsonPath('data.streak_days', 1)->assertJsonCount(1, 'data.weekly_moods')
        ->assertJsonPath('data.weekly_moods.0.count', 1);
});

it('dashboard excludes next-day future entries and counts midnight only once', function () {
    $user = dashboardUser();
    $midnight = dashboardMood($user, '2026-10-08 00:00:00');
    dashboardMood($user, '2026-10-07 23:59:59');
    dashboardMood($user, '2026-10-09 00:00:00');
    $this->actingAs($user, 'sanctum')->getJson('/api/v1/dashboard')
        ->assertOk()->assertJsonPath('data.today_mood.id', $midnight->id)
        ->assertJsonPath('data.streak_days', 2)->assertJsonCount(2, 'data.recent_entries')
        ->assertJsonPath('data.weekly_moods.0.count', 1)->assertJsonPath('data.weekly_moods.1.count', 1);
});

it('dashboard handles the twenty three hour spring DST day', function () {
    Carbon::setTestNow(Carbon::parse('2026-03-08 18:00:00', 'UTC'));
    $user = dashboardUser('America/New_York');
    dashboardMood($user, '2026-03-08 04:59:59'); // Yesterday.
    dashboardMood($user, '2026-03-08 05:00:00'); // Today starts.
    $latest = dashboardMood($user, '2026-03-09 03:59:59'); // Today ends.
    dashboardMood($user, '2026-03-09 04:00:00'); // Tomorrow.
    $this->actingAs($user, 'sanctum')->getJson('/api/v1/dashboard')
        ->assertOk()->assertJsonPath('data.today_mood.id', $latest->id)
        ->assertJsonPath('data.streak_days', 2)->assertJsonCount(3, 'data.recent_entries')
        ->assertJsonPath('data.weekly_moods.1.day', '2026-03-08')
        ->assertJsonPath('data.weekly_moods.1.count', 2);
});

it('dashboard handles the twenty five hour fall DST day including repeated hour', function () {
    Carbon::setTestNow(Carbon::parse('2026-11-01 18:00:00', 'UTC'));
    $user = dashboardUser('America/New_York');
    dashboardMood($user, '2026-11-01 03:59:59');
    dashboardMood($user, '2026-11-01 05:30:00'); // 01:30 EDT.
    dashboardMood($user, '2026-11-01 06:30:00'); // 01:30 EST.
    $latest = dashboardMood($user, '2026-11-02 04:59:59');
    dashboardMood($user, '2026-11-02 05:00:00');
    $this->actingAs($user, 'sanctum')->getJson('/api/v1/dashboard')
        ->assertOk()->assertJsonPath('data.today_mood.id', $latest->id)
        ->assertJsonPath('data.streak_days', 2)->assertJsonCount(4, 'data.recent_entries')
        ->assertJsonPath('data.weekly_moods.1.day', '2026-11-01')
        ->assertJsonPath('data.weekly_moods.1.count', 3);
});

it('dashboard imports long streaks without fetching mood content for accounting', function () {
    $user = dashboardUser();
    for ($day = 0; $day < 100; $day++) {
        dashboardMood($user, now()->subDays($day)->toDateTimeString());
    }
    DB::enableQueryLog();
    DB::flushQueryLog();
    $this->actingAs($user, 'sanctum')->getJson('/api/v1/dashboard')
        ->assertOk()->assertJsonPath('data.streak_days', 100);
    $moodQueries = array_filter(DB::getQueryLog(), fn ($query) => str_contains($query['query'], 'mood_entries'));
    DB::disableQueryLog();
    // Weekly + today + recent + one minimal accounting projection.
    expect(count($moodQueries))->toBe(4);
    $accountingQueries = array_values(array_filter($moodQueries, fn ($query) => str_contains($query['query'], '"client_created_at", "created_at"')));
    expect($accountingQueries)->toHaveCount(1);
    expect($accountingQueries[0]['query'])->not->toContain('intensity');
});
