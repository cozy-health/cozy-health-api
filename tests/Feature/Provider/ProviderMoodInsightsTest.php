<?php

namespace Tests\Feature\Provider;

use App\Models\MoodEntry;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class ProviderMoodInsightsTest extends ProviderDataTestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_roster_priority_uses_distinct_patient_local_negative_days_and_explicit_empty_states(): void
    {
        Carbon::setTestNow('2026-11-06 12:00:00 UTC');
        $provider = $this->provider();
        $high = User::factory()->create(['name' => 'High Patient', 'timezone' => 'Africa/Lagos']);
        $none = User::factory()->create(['name' => 'None Patient', 'timezone' => 'UTC']);
        $private = User::factory()->create(['name' => 'Private Patient', 'timezone' => 'UTC']);
        $this->link($provider, $high, ['consent_moods' => true]);
        $this->link($provider, $none, ['consent_moods' => true]);
        $this->link($provider, $private, ['consent_moods' => false]);
        foreach (['2026-11-02 10:00:00', '2026-11-03 10:00:00', '2026-11-04 10:00:00', '2026-11-05 10:00:00', '2026-11-06 10:00:00'] as $at) {
            $this->mood($high, 'sad', $at);
        }
        $this->mood($high, 'happy', '2026-11-06 11:00:00'); // Mixed day stays negative.
        $this->mood($high, 'angry', '2026-11-06 11:30:00'); // Same day counts once.
        $this->mood($high, 'sad', '2026-10-29 10:00:00'); // Outside the seven-day window.

        $rows = $this->asProvider($provider)->getJson('/api/v1/provider/clients')->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')->json();
        $byId = collect($rows)->keyBy('user_id');
        $this->assertSame('high', $byId[$high->id]['mood_summary']['priority']['level']);
        $this->assertSame(5, $byId[$high->id]['mood_summary']['priority']['negative_days']);
        $this->assertSame(5, $byId[$high->id]['mood_summary']['coverage']['recorded_days']);
        $this->assertSame('no_checkins', $byId[$none->id]['mood_summary']['priority']['status']);
        $this->assertNull($byId[$private->id]['mood_summary']);
    }

    public function test_insights_cover_complete_period_and_return_daily_and_monthly_buckets(): void
    {
        Carbon::setTestNow('2026-11-06 12:00:00 UTC');
        $provider = $this->provider();
        $user = User::factory()->create(['timezone' => 'UTC']);
        $this->link($provider, $user, ['consent_moods' => true]);
        foreach (range(1, 25) as $day) {
            $this->mood($user, $day % 2 ? 'happy' : 'upset', sprintf('2026-10-%02d 12:00:00', $day), $day % 5 + 1);
        }
        $this->mood($user, 'okay', '2026-11-01 12:00:00');
        $this->mood($user, 'tired', '2026-11-02 12:00:00');
        $this->mood($user, 'unknown-old-label', '2026-11-03 12:00:00');

        $this->asProvider($provider);
        $month = $this->getJson("/api/v1/provider/clients/{$user->id}/insights?period=month")->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('data.period.start', '2026-11-01')
            ->assertJsonPath('data.period.end', '2026-11-06')->assertJsonCount(6, 'data.buckets')
            ->assertJsonPath('data.counts.neutral', 1)->assertJsonPath('data.counts.unclassified', 2);
        $this->assertSame(3, $month->json('data.coverage.check_ins'));

        $year = $this->getJson("/api/v1/provider/clients/{$user->id}/insights?period=year")->assertOk()
            ->assertJsonCount(11, 'data.buckets')->assertJsonPath('data.coverage.check_ins', 28);
        $october = collect($year->json('data.buckets'))->firstWhere('key', '2026-10');
        $this->assertSame(25, $october['check_ins']);
        $this->assertDatabaseHas('provider_access_logs', ['provider_id' => $provider->id, 'user_id' => $user->id,
            'resource_type' => 'mood_insight', 'resource_id' => 'year']);
    }

    public function test_priority_boundaries_are_low_moderate_and_high_without_counting_future_or_deleted_records(): void
    {
        Carbon::setTestNow('2026-11-06 12:00:00 UTC');
        $provider = $this->provider();
        $users = [];
        foreach ([2 => 'low', 4 => 'moderate', 7 => 'high'] as $negativeDays => $expected) {
            $user = User::factory()->create(['timezone' => 'UTC']);
            $this->link($provider, $user, ['consent_moods' => true]);
            foreach (range(0, $negativeDays - 1) as $offset) $this->mood($user, 'upset', now('UTC')->subDays($offset)->format('Y-m-d 09:00:00'));
            $this->mood($user, 'sad', '2026-11-06 13:00:00'); // Future today is excluded.
            $deleted = $this->mood($user, 'angry', '2026-11-01 08:00:00');
            $deleted->delete();
            $users[$user->id] = $expected;
        }
        $rows = collect($this->asProvider($provider)->getJson('/api/v1/provider/clients')->assertOk()->json())->keyBy('user_id');
        foreach ($users as $userId => $expected) $this->assertSame($expected, $rows[$userId]['mood_summary']['priority']['level']);
    }

    public function test_insights_validate_period_and_recheck_consent_and_patient_timezone_for_drill_down(): void
    {
        Carbon::setTestNow('2026-11-06 12:00:00 UTC');
        $provider = $this->provider();
        $user = User::factory()->create(['timezone' => 'Pacific/Kiritimati']);
        $link = $this->link($provider, $user, ['consent_moods' => true]);
        $entry = $this->mood($user, 'anxious', '2026-11-05 10:30:00'); // 2026-11-06 locally.
        $this->asProvider($provider)
            ->getJson("/api/v1/provider/clients/{$user->id}/insights?period=invalid")->assertUnprocessable();
        $this->getJson("/api/v1/provider/clients/{$user->id}/insights?period=week")->assertOk()
            ->assertJsonPath('data.period.timezone', 'Pacific/Kiritimati');
        $this->getJson("/api/v1/provider/clients/{$user->id}/moods?from=2026-11-06&to=2026-11-06")
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $entry->id);
        $link->update(['consent_moods' => false]);
        $this->getJson("/api/v1/provider/clients/{$user->id}/insights?period=week")->assertNotFound();
        $this->assertDatabaseMissing('provider_access_logs', ['resource_type' => 'mood_insight', 'resource_id' => 'invalid']);
    }

    private function mood(User $user, string $mood, string $at, int $intensity = 3): MoodEntry
    {
        return MoodEntry::create(['id' => (string) Str::uuid(), 'user_id' => $user->id, 'mood' => $mood,
            'intensity' => $intensity, 'triggers' => ['work'], 'coping_strategies' => ['breathing'], 'client_created_at' => $at]);
    }
}
