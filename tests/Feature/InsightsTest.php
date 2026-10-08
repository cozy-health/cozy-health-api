<?php

use App\Models\MoodEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-08 12:00:00', 'UTC'));
    Cache::flush();
});

afterEach(function () {
    Carbon::setTestNow();
});

function insightsMood(User $user, string $time = '2026-10-08 10:00:00', array $attributes = []): MoodEntry
{
    return MoodEntry::create(array_merge([
        'id' => (string) Str::uuid(), 'user_id' => $user->id, 'mood' => 'good',
        'intensity' => 6, 'client_created_at' => Carbon::parse($time, 'UTC'),
    ], $attributes));
}

test('weekly_returns_empty_for_new_user', function () {
    $this->actingAs(User::factory()->create(), 'sanctum')->getJson('/api/v1/insights/weekly')
        ->assertOk()->assertExactJson(['success' => true, 'message' => 'Weekly insights retrieved.', 'data' => ['weeks' => []]]);
});

test('weekly_returns_one_week_by_default', function () {
    $user = User::factory()->create(['timezone' => 'UTC']);
    insightsMood($user, attributes: ['intensity' => 8]);
    insightsMood($user, attributes: ['intensity' => 2, 'mood' => 'low']);
    insightsMood($user, attributes: ['intensity' => 5]);
    insightsMood($user, '2026-10-04 23:59:59');
    $this->actingAs($user, 'sanctum')->getJson('/api/v1/insights/weekly')->assertOk()
        ->assertJsonCount(1, 'data.weeks')->assertJsonPath('data.weeks.0.week_start', '2026-10-05')
        ->assertJsonPath('data.weeks.0.avg_intensity', 5)->assertJsonPath('data.weeks.0.entry_count', 3)
        ->assertJsonPath('data.weeks.0.top_mood', 'good')->assertJsonPath('data.weeks.0.mood_breakdown', ['good' => 2, 'low' => 1]);
});

test('weekly_returns_four_weeks_when_requested', function () {
    $user = User::factory()->create(['timezone' => 'UTC']);
    foreach (range(0, 4) as $week) insightsMood($user, now()->subWeeks($week)->toDateTimeString());
    $this->actingAs($user, 'sanctum')->getJson('/api/v1/insights/weekly?weeks=4')->assertOk()
        ->assertJsonCount(4, 'data.weeks')->assertJsonPath('data.weeks.0.week_start', '2026-09-14');
});

test('weekly_returns_twelve_weeks_when_requested', function () {
    $user = User::factory()->create(['timezone' => 'UTC']);
    foreach (range(0, 12) as $week) insightsMood($user, now()->subWeeks($week)->toDateTimeString());
    $this->actingAs($user, 'sanctum')->getJson('/api/v1/insights/weekly?weeks=12')->assertOk()->assertJsonCount(12, 'data.weeks');
});

test('weekly_rejects_invalid_weeks_param', function () {
    $this->actingAs(User::factory()->create(), 'sanctum');
    foreach (['0', '2', '13', 'abc', '1.5', ''] as $value) {
        $this->getJson('/api/v1/insights/weekly?weeks='.$value)->assertUnprocessable()->assertJsonValidationErrors('weeks')->assertJsonPath('success', false);
    }
});

test('weekly_respects_user_timezone', function () {
    $user = User::factory()->create(['timezone' => 'Pacific/Auckland']);
    insightsMood($user, '2026-10-04 10:59:59');
    insightsMood($user, '2026-10-04 11:00:00');
    insightsMood($user, '2026-10-09 10:59:59');
    insightsMood($user, '2026-10-09 11:00:00');
    $this->actingAs($user, 'sanctum')->getJson('/api/v1/insights/weekly')->assertOk()
        ->assertJsonPath('data.weeks.0.week_start', '2026-10-05')->assertJsonPath('data.weeks.0.entry_count', 2);
});

test('weekly_excludes_deleted_entries', function () {
    $user = User::factory()->create();
    insightsMood($user, attributes: ['is_deleted' => true]);
    $this->actingAs($user, 'sanctum')->getJson('/api/v1/insights/weekly')->assertOk()->assertJsonPath('data.weeks', []);
});

test('triggers_returns_empty_for_new_user', function () {
    $this->actingAs(User::factory()->create(), 'sanctum')->getJson('/api/v1/insights/triggers')->assertOk()
        ->assertJsonPath('data.range_days', 30)->assertJsonPath('data.triggers', []);
});

test('triggers_aggregates_by_name', function () {
    $user = User::factory()->create();
    insightsMood($user, attributes: ['triggers' => ['Work', 'Sleep', 'Work', ''], 'intensity' => 8]);
    insightsMood($user, attributes: ['triggers' => ['Work'], 'intensity' => 4]);
    insightsMood($user, attributes: ['triggers' => []]);
    insightsMood($user);
    $this->actingAs($user, 'sanctum')->getJson('/api/v1/insights/triggers')->assertOk()->assertJsonPath('data.triggers', [
        ['name' => 'Work', 'count' => 2, 'avg_intensity' => 6],
        ['name' => 'Sleep', 'count' => 1, 'avg_intensity' => 8],
    ]);
});

test('triggers_respects_days_param', function () {
    $user = User::factory()->create(['timezone' => 'UTC']);
    foreach ([0, 6, 7, 29, 30, 89, 90] as $day) insightsMood($user, now()->subDays($day)->toDateTimeString(), ['triggers' => ['Work']]);
    $this->actingAs($user, 'sanctum');
    foreach ([7 => 2, 30 => 4, 90 => 6] as $days => $count) {
        $this->getJson('/api/v1/insights/triggers?days='.$days)->assertOk()->assertJsonPath('data.range_days', $days)->assertJsonPath('data.triggers.0.count', $count);
    }
});

test('triggers_rejects_invalid_days', function () {
    $this->actingAs(User::factory()->create(), 'sanctum');
    foreach (['0', '8', '91', 'abc', '7.5', ''] as $days) {
        $this->getJson('/api/v1/insights/triggers?days='.$days)->assertUnprocessable()->assertJsonValidationErrors('days');
    }
});

test('sleep_mood_returns_empty_for_new_user', function () {
    $this->actingAs(User::factory()->create(), 'sanctum')->getJson('/api/v1/insights/sleep-mood')->assertOk()
        ->assertJsonPath('data.range_days', 30)->assertJsonPath('data.points', []);
});

test('sleep_mood_only_counts_entries_with_sleep_quality', function () {
    $user = User::factory()->create();
    insightsMood($user);
    insightsMood($user, attributes: ['sleep_quality' => 3, 'intensity' => 7]);
    insightsMood($user, attributes: ['sleep_quality' => 3, 'intensity' => 2]);
    $this->actingAs($user, 'sanctum')->getJson('/api/v1/insights/sleep-mood')->assertOk()
        ->assertJsonPath('data.points', [['sleep_quality' => 3, 'avg_intensity' => 4.5, 'count' => 2]]);
});

test('sleep_mood_returns_five_buckets_max', function () {
    $user = User::factory()->create();
    foreach (range(0, 6) as $quality) insightsMood($user, attributes: ['sleep_quality' => $quality]);
    $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/insights/sleep-mood')->assertOk()->assertJsonCount(5, 'data.points');
    expect(array_column($response->json('data.points'), 'sleep_quality'))->toBe([1, 2, 3, 4, 5]);
});

test('sleep_mood_respects_days_param', function () {
    $user = User::factory()->create(['timezone' => 'UTC']);
    foreach ([0, 6, 7, 29, 30, 89, 90] as $day) insightsMood($user, now()->subDays($day)->toDateTimeString(), ['sleep_quality' => 4]);
    $this->actingAs($user, 'sanctum');
    foreach ([7 => 2, 30 => 4, 90 => 6] as $days => $count) {
        $this->getJson('/api/v1/insights/sleep-mood?days='.$days)->assertOk()->assertJsonPath('data.range_days', $days)->assertJsonPath('data.points.0.count', $count);
    }
});

test('all_endpoints_require_auth', function () {
    foreach (['weekly', 'triggers', 'sleep-mood'] as $endpoint) $this->getJson('/api/v1/insights/'.$endpoint)->assertUnauthorized();
});

test('all_endpoints_are_throttled', function () {
    $this->actingAs(User::factory()->create(), 'sanctum');
    foreach (['weekly', 'triggers', 'sleep-mood'] as $endpoint) {
        Cache::flush();
        for ($i = 0; $i < 60; $i++) $this->getJson('/api/v1/insights/'.$endpoint)->assertOk();
        $this->getJson('/api/v1/insights/'.$endpoint)->assertStatus(429);
        Carbon::setTestNow(now()->addSeconds(61));
        $this->getJson('/api/v1/insights/'.$endpoint)->assertOk();
    }
});

test('all_insights_isolate_users_and_exclude_deleted_records', function () {
    $user = User::factory()->create();
    insightsMood(User::factory()->create(), attributes: ['triggers' => ['Work'], 'sleep_quality' => 3]);
    insightsMood($user, attributes: ['triggers' => ['Work'], 'sleep_quality' => 3, 'is_deleted' => true]);
    $this->actingAs($user, 'sanctum');
    foreach (['weekly' => 'weeks', 'triggers' => 'triggers', 'sleep-mood' => 'points'] as $endpoint => $key) {
        $this->getJson('/api/v1/insights/'.$endpoint)->assertOk()->assertJsonPath('data.'.$key, []);
    }
});

test('day_ranges_respect_dst_midnight_and_legacy_timestamps', function () {
    Carbon::setTestNow(Carbon::parse('2026-11-02 12:00:00', 'UTC'));
    $user = User::factory()->create(['timezone' => 'America/New_York']);
    $attributes = ['triggers' => ['Sleep'], 'sleep_quality' => 5];
    insightsMood($user, '2026-10-27 03:59:59', $attributes);
    insightsMood($user, '2026-10-27 04:00:00', $attributes);
    insightsMood($user, '2026-11-03 04:59:59', $attributes);
    insightsMood($user, '2026-11-03 05:00:00', $attributes);
    insightsMood($user, attributes: array_merge($attributes, ['client_created_at' => null]));
    $this->actingAs($user, 'sanctum')->getJson('/api/v1/insights/triggers?days=7')->assertOk()->assertJsonPath('data.triggers.0.count', 3);
    $this->getJson('/api/v1/insights/sleep-mood?days=7')->assertOk()->assertJsonPath('data.points.0.count', 3);
});

test('sleep_mood_rejects_invalid_days', function () {
    $this->actingAs(User::factory()->create(), 'sanctum')->getJson('/api/v1/insights/sleep-mood?days=8')
        ->assertUnprocessable()->assertJsonValidationErrors('days');
});
