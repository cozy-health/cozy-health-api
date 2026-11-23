<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminExactMetricsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now('UTC')->setDate(2026, 11, 23)->setTime(12, 0));
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $this->withToken($admin->createToken('cozy-admin-management-v1', ['admin:metrics'], now()->addMinutes(30))->plainTextToken);
    }

    private function mood(User $user, string $at, int $intensity): void
    {
        DB::table('mood_entries')->insert(['id' => (string) Str::uuid(), 'user_id' => $user->id,
            'mood' => 'good', 'intensity' => $intensity, 'note' => 'PRIVATE NOTE MUST NOT APPEAR',
            'is_deleted' => false, 'created_at' => $at, 'updated_at' => $at]);
    }

    public function test_exact_internal_counts_include_today_and_respect_calendar_boundaries(): void
    {
        $first = User::factory()->create(['created_at' => '2026-11-01 08:00:00']);
        $second = User::factory()->create(['created_at' => '2026-11-02 08:00:00']);
        User::factory()->create(['created_at' => '2026-11-23 08:00:00']);
        $this->mood($first, '2026-11-23 09:00:00', 3);
        $this->mood($first, '2026-11-17 00:00:00', 8);
        $this->mood($second, '2026-11-16 23:59:59', 4);
        $demo = User::factory()->create(['is_demo' => true]);
        $this->mood($demo, '2026-11-23 09:00:00', 10);

        $response = $this->getJson('/api/v1/admin/metrics/insights?range=7d&view=internal')->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('meta.view', 'internal')->assertJsonPath('meta.minimum_cohort', 0)
            ->assertJsonPath('meta.window.from', '2026-11-17')
            ->assertJsonPath('data.summary.active_users.count', 1)
            ->assertJsonPath('data.summary.new_users.count', 1)
            ->assertJsonPath('data.summary.community_posts.count', 0)
            ->assertJsonPath('data.summary.average_intensity.count', 5.5);
        $this->assertSame('2026-11-23', collect($response->json('data.daily'))->last()['date']);
        $this->assertStringNotContainsString('PRIVATE NOTE', $response->getContent());
        $this->assertStringNotContainsString('"user_id"', $response->getContent());
        foreach (['7d' => 2, '30d' => 3, '90d' => 3] as $range => $count) {
            $this->getJson('/api/v1/admin/metrics/usage?view=internal&range='.$range)
                ->assertOk()->assertJsonPath('data.features.0.status', 'available')
                ->assertJsonPath('data.features.0.count', $count);
        }
        $this->getJson('/api/v1/admin/metrics/insights?range=30d&view=internal')
            ->assertOk()->assertJsonPath('data.summary.new_users.count', 3);
    }

    public function test_internal_cache_does_not_unmask_or_modify_published_reports(): void
    {
        $user = User::factory()->create(['created_at' => '2026-10-01 08:00:00']);
        $this->mood($user, '2026-10-29 09:00:00', 4);
        $this->getJson('/api/v1/admin/metrics/usage?view=internal&range=90d')->assertOk()
            ->assertJsonPath('data.features.0.count', 1);
        $this->assertDatabaseCount('admin_metric_releases', 0);
        $this->getJson('/api/v1/admin/metrics/usage?range=90d')->assertOk()
            ->assertJsonPath('data.features.0.count', '<5');
        $published = DB::table('admin_metric_releases')->sole();
        $this->assertStringStartsWith('admin-metrics:v1:', $published->release_key);
        $this->getJson('/api/v1/admin/metrics/usage?view=internal&range=90d')
            ->assertOk()->assertJsonPath('data.features.0.count', 1);
        $this->assertSame($published->payload, DB::table('admin_metric_releases')->sole()->payload);
    }

    public function test_small_retention_denominators_are_exact_only_for_internal_view(): void
    {
        $first = User::factory()->create(['created_at' => '2026-10-05 08:00:00']);
        User::factory()->create(['created_at' => '2026-10-05 08:00:00']);
        $this->mood($first, '2026-10-06 08:00:00', 3);
        $response = $this->getJson('/api/v1/admin/metrics/retention?view=internal')->assertOk();
        $row = collect($response->json('data'))->firstWhere('week', '2026-10-05');
        $this->assertSame(2, $row['signups']['count']);
        $this->assertSame(1, $row['retention']['d1']['count']);
        $this->assertSame(2, $row['retention']['d1']['cohort']);
        $this->assertEquals(50, $row['retention']['d1']['rate']);
        $empty = collect($response->json('data'))->firstWhere('week', '2026-10-12');
        $this->assertNull($empty['retention']['d1']['rate']);
        $this->getJson('/api/v1/admin/metrics/safety?view=internal')->assertOk()->assertJsonPath('data.0.count', 0);
    }

    public function test_published_retention_keeps_existing_whole_percent_precision(): void
    {
        for ($i = 0; $i < 15; $i++) {
            $user = User::factory()->create(['created_at' => '2026-09-07 08:00:00']);
            if ($i < 5) foreach (['2026-09-08', '2026-09-14', '2026-10-07'] as $day) $this->mood($user, $day.' 08:00:00', 4);
        }
        $published = collect($this->getJson('/api/v1/admin/metrics/retention')->assertOk()->json('data'))->firstWhere('week', '2026-09-07');
        $internal = collect($this->getJson('/api/v1/admin/metrics/retention?view=internal')->assertOk()->json('data'))->firstWhere('week', '2026-09-07');
        $this->assertSame(33, $published['retention']['d1']['rate']);
        $this->assertSame(33.3, $internal['retention']['d1']['rate']);
    }

    public function test_internal_view_retains_role_and_fixed_filter_restrictions(): void
    {
        $this->getJson('/api/v1/admin/metrics/insights?view=unknown')->assertUnprocessable();
        $this->getJson('/api/v1/admin/metrics/insights?view=internal&user_id=99')->assertUnprocessable();
        app('auth')->forgetGuards();
        $this->withToken(\App\Models\Provider::create(['name' => 'Synthetic provider', 'email' => 'exact-provider@example.test', 'password_hash' => 'FixtureProvider9!'])->createToken('provider')->plainTextToken)
            ->getJson('/api/v1/admin/metrics/insights?view=internal')->assertUnauthorized()->assertJsonMissingPath('data');
        app('auth')->forgetGuards();
        $this->withToken(User::factory()->create()->createToken('patient')->plainTextToken)
            ->getJson('/api/v1/admin/metrics/insights?view=internal')->assertForbidden();
        app('auth')->forgetGuards();
        $this->withHeaders(['Authorization' => ''])->getJson('/api/v1/admin/metrics/usage?view=internal')->assertUnauthorized();
    }
}
