<?php

use App\Models\Affirmation;
use App\Models\User;
use Database\Seeders\AffirmationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('reading is idempotent owned and rejects future timestamps', function () {
    $user = User::factory()->create(['timezone' => 'Africa/Lagos']);
    $this->actingAs($user, 'sanctum');
    $body = ['read_at' => now()->toIso8601String()];
    $this->postJson('/api/v1/affirmations/read', $body)->assertOk();
    $this->postJson('/api/v1/affirmations/read', $body)->assertOk();
    expect(DB::table('user_affirmation_reads')->where('user_id', $user->id)->count())->toBe(1);
    $this->postJson('/api/v1/affirmations/read', ['read_at' => now()->addDay()->toIso8601String()])->assertUnprocessable();
    expect(DB::table('user_affirmation_reads')->where('user_id', '!=', $user->id)->count())->toBe(0);
});

test('offline read sync succeeds while paid entitlement sync is rejected', function () {
    $user = User::factory()->create();
    $this->actingAs($user, 'sanctum')->postJson('/api/v1/sync/batch', ['operations' => [
        ['type' => 'affirmation_read', 'action' => 'upsert', 'data' => ['id' => 'read-one', 'read_at' => now()->toIso8601String()]],
        ['type' => 'subscription', 'action' => 'upsert', 'data' => ['id' => 'forged', 'status' => 'active']],
    ]])->assertOk()->assertJsonPath('data.results.0.success', true)
        ->assertJsonPath('data.results.1.error_code', 'access_denied');
    expect(DB::table('subscription_statuses')->where('user_id', $user->id)->count())->toBe(0);
});

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
            'data' => ['id' => $affirmation->id, 'body' => $affirmation->body, 'date' => '2026-10-08',
                'assigned_at' => '2026-10-08T12:00:00+00:00', 'expires_at' => '2026-10-09T12:00:00+00:00'],
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
        if ($previous !== null) {
            expect($body)->not->toBe($previous);
        }
        $previous = $body;
    }
});

test('today_returns_empty_when_no_affirmations', function () {
    $this->actingAs(User::factory()->create(), 'sanctum')->getJson('/api/v1/affirmations/today')
        ->assertOk()->assertExactJson(['success' => true, 'message' => 'No affirmations available.', 'data' => null]);
});

test('legacy_date_changes_at_local_midnight_but_assignment_does_not', function () {
    $this->seed(AffirmationSeeder::class);
    $this->actingAs(User::factory()->create(['timezone' => 'Pacific/Auckland']), 'sanctum');
    Carbon::setTestNow(Carbon::parse('2026-10-08 10:59:59', 'UTC'));
    $before = $this->getJson('/api/v1/affirmations/today')->assertOk()->assertJsonPath('data.date', '2026-10-08')->json('data.body');
    Carbon::setTestNow(Carbon::parse('2026-10-08 11:00:00', 'UTC'));
    $after = $this->getJson('/api/v1/affirmations/today')->assertOk()->assertJsonPath('data.date', '2026-10-09')->json('data.body');
    expect($after)->toBe($before);
});

test('today_requires_auth', function () {
    $this->getJson('/api/v1/affirmations/today')->assertUnauthorized();
});

test('today_is_throttled', function () {
    $this->actingAs(User::factory()->create(), 'sanctum');
    for ($attempt = 0; $attempt < 60; $attempt++) {
        $this->getJson('/api/v1/affirmations/today')->assertOk();
    }
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

test('assignment_advances_at_exactly_24_hours_and_not_before', function () {
    $this->seed(AffirmationSeeder::class);
    $this->actingAs(User::factory()->create(), 'sanctum');
    $first = $this->getJson('/api/v1/affirmations/today')->assertOk()->json('data');
    Carbon::setTestNow(Carbon::parse('2026-10-09 11:59:59', 'UTC'));
    $this->getJson('/api/v1/affirmations/today')->assertOk()->assertJsonPath('data.id', $first['id']);
    Carbon::setTestNow(Carbon::parse('2026-10-09 12:00:00', 'UTC'));
    $next = $this->getJson('/api/v1/affirmations/today')->assertOk()->json('data');
    expect($next['id'])->not->toBe($first['id']);
    expect($next['assigned_at'])->toBe('2026-10-09T12:00:00+00:00');
    expect($next['expires_at'])->toBe('2026-10-10T12:00:00+00:00');
    expect(DB::table('user_affirmation_assignments')->count())->toBe(1);
});

test('returning_after_several_days_advances_once_and_starts_a_fresh_window', function () {
    $this->seed(AffirmationSeeder::class);
    $this->actingAs(User::factory()->create(), 'sanctum');
    $first = $this->getJson('/api/v1/affirmations/today')->json('data.id');
    $expected = Affirmation::where('id', '>', $first)->orderBy('id')->value('id') ?? Affirmation::min('id');
    Carbon::setTestNow(Carbon::parse('2026-10-13 16:30:00', 'UTC'));
    $this->getJson('/api/v1/affirmations/today')->assertOk()->assertJsonPath('data.id', $expected)
        ->assertJsonPath('data.expires_at', '2026-10-14T16:30:00+00:00');
});

test('rotation_visits_the_entire_catalog_before_repeating', function () {
    $this->seed(AffirmationSeeder::class);
    $this->actingAs(User::factory()->create(), 'sanctum');
    $seen = [];
    for ($day = 0; $day <= 30; $day++) {
        Carbon::setTestNow(Carbon::parse('2026-10-08 12:00:00', 'UTC')->addDays($day));
        $seen[] = $this->getJson('/api/v1/affirmations/today')->assertOk()->json('data.id');
    }
    expect(array_unique(array_slice($seen, 0, 30)))->toHaveCount(30);
    expect($seen[30])->toBe($seen[0]);
});

test('timezone_changes_do_not_shorten_the_assignment', function () {
    $this->seed(AffirmationSeeder::class);
    $this->actingAs($user = User::factory()->create(['timezone' => 'Pacific/Auckland']), 'sanctum');
    $first = $this->getJson('/api/v1/affirmations/today')->json('data');
    $user->update(['timezone' => 'America/New_York']);
    Carbon::setTestNow(now()->addHours(23));
    $next = $this->getJson('/api/v1/affirmations/today')->assertOk()->json('data');
    expect($next['id'])->toBe($first['id']);
    expect($next['expires_at'])->toBe($first['expires_at']);
});

test('disabled_or_deleted_current_entry_is_replaced_immediately', function () {
    $this->seed(AffirmationSeeder::class);
    $this->actingAs(User::factory()->create(), 'sanctum');
    $id = $this->getJson('/api/v1/affirmations/today')->json('data.id');
    Affirmation::findOrFail($id)->update(['is_active' => false]);
    $next = $this->getJson('/api/v1/affirmations/today')->assertOk()->json('data.id');
    expect($next)->not->toBe($id);
    Affirmation::findOrFail($next)->delete();
    expect($this->getJson('/api/v1/affirmations/today')->assertOk()->json('data.id'))->not->toBeIn([$id, $next]);
});

test('single_entry_can_repeat_and_empty_catalog_can_recover', function () {
    $entry = Affirmation::create(['body' => 'One gentle step.']);
    $this->actingAs(User::factory()->create(), 'sanctum');
    $this->getJson('/api/v1/affirmations/today')->assertJsonPath('data.id', $entry->id);
    Carbon::setTestNow(now()->addDays(3));
    $this->getJson('/api/v1/affirmations/today')->assertJsonPath('data.id', $entry->id)
        ->assertJsonPath('data.expires_at', '2026-10-12T12:00:00+00:00');
    $entry->update(['is_active' => false]);
    $this->getJson('/api/v1/affirmations/today')->assertJsonPath('data', null);
    $entry->update(['is_active' => true]);
    $this->getJson('/api/v1/affirmations/today')->assertJsonPath('data.id', $entry->id);
});

test('assignments_are_per_user_persist_across_sessions_and_cascade_on_deletion', function () {
    $this->seed(AffirmationSeeder::class);
    $one = User::factory()->create();
    $two = User::factory()->create();
    $this->actingAs($one, 'sanctum');
    $first = $this->getJson('/api/v1/affirmations/today')->json('data');
    $this->actingAs($two, 'sanctum')->getJson('/api/v1/affirmations/today')->assertOk();
    $this->actingAs($one->fresh(), 'sanctum')->getJson('/api/v1/affirmations/today')->assertJsonPath('data', $first);
    expect(DB::table('user_affirmation_assignments')->count())->toBe(2);
    $one->forceDelete();
    expect(DB::table('user_affirmation_assignments')->where('user_id', $one->id)->exists())->toBeFalse();
    expect(DB::table('user_affirmation_assignments')->where('user_id', $two->id)->exists())->toBeTrue();
});
