<?php

use App\Models\MoodEntry;
use App\Models\User;
use App\Services\StreakService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

afterEach(fn () => Carbon::setTestNow());

it('keeps rest accounting owned, durable, and erased with the account', function () {
    Carbon::setTestNow('2026-10-01 12:00:00');
    $user = User::factory()->create(['timezone' => 'UTC']);
    $other = User::factory()->create(['timezone' => 'UTC']);
    $service = app(StreakService::class);
    $service->snapshot($user);
    for ($day = 1; $day <= 7; $day++) {
        Carbon::setTestNow(sprintf('2026-10-%02d 12:00:00', $day));
        $mood = MoodEntry::create(['id' => (string) Str::uuid(), 'user_id' => $user->id,
            'mood' => 'good', 'intensity' => 6, 'client_created_at' => now()]);
        $service->snapshot($user);
    }
    Carbon::setTestNow('2026-10-09 12:00:00');
    $this->actingAs($user, 'sanctum')->getJson('/api/v1/wellness/streak')
        ->assertOk()->assertJsonPath('data.current_days', 7)->assertJsonPath('data.rest_days', 0);
    $mood->delete();
    DB::enableQueryLog();
    DB::flushQueryLog();
    $service->snapshot($user);
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    expect(array_filter($queries, fn ($query) => str_contains($query['query'], 'insert') && str_contains($query['query'], 'streak_completions')))->toBeEmpty();
    expect($service->snapshot($user)['current_days'])->toBe(7)
        ->and($service->snapshot($other)['current_days'])->toBe(0);
    $user->forceDelete();
    expect(DB::table('streak_completions')->where('user_id', $user->id)->count())->toBe(0)
        ->and(DB::table('streak_accounts')->where('user_id', $user->id)->count())->toBe(0);
});

it('requires authentication to read a streak', function () {
    $this->getJson('/api/v1/wellness/streak')->assertUnauthorized();
});
