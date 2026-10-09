<?php
namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminMetricsTest extends TestCase
{
    use RefreshDatabase;
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 9)->setTime(12, 0));
        $admin = User::factory()->create(); $admin->forceFill(['role' => 'admin'])->save();
        $this->withToken($admin->createToken('cozy-admin-dashboard', ['admin:metrics'], now()->addMinutes(30))->plainTextToken);
    }
    private function consumer(string $signup = '2026-06-01'): User
    {
        return User::factory()->create(['created_at' => $signup.' 12:00:00']);
    }
    private function record(User $user, string $date, bool $flag = false, string $table = 'mood_entries'): void
    {
        $row = ['id' => (string) Str::uuid(), 'user_id' => $user->id, 'is_crisis_flagged' => $flag,
            'created_at' => $date.' 12:00:00', 'updated_at' => $date.' 12:00:00'];
        if ($table === 'mood_entries') $row += ['mood' => 'good', 'intensity' => 5, 'note' => 'NEVER RETURN THIS'];
        if ($table === 'journal_entries') $row += ['type' => 'free', 'body' => 'NEVER RETURN THIS', 'title' => 'PRIVATE TITLE'];
        if ($table === 'quiz_attempts') $row += ['quiz_slug' => 'test', 'quiz_title' => 'private', 'answers' => '{}', 'score' => 5, 'completed_at' => $date.' 12:00:00'];
        DB::table($table)->insert($row);
    }
    public function test_endpoints_require_role_session_and_fixed_filters(): void
    {
        foreach (['overview', 'usage', 'retention', 'safety'] as $metric) $this->getJson('/api/v1/admin/metrics/'.$metric)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->getJson('/admin/metrics/overview')->assertOk();
        foreach (['range=1d', 'from=2026-01-01', 'user_id=1'] as $query) $this->getJson('/api/v1/admin/metrics/usage?'.$query)->assertUnprocessable();
        app('auth')->forgetGuards();
        $this->withToken(User::factory()->create()->createToken('mobile')->plainTextToken)->getJson('/api/v1/admin/metrics/overview')->assertForbidden();
    }
    public function test_many_events_from_four_users_are_suppressed(): void
    {
        foreach (range(1, 4) as $_) {
            $user = $this->consumer();
            foreach (['2026-09-29', '2026-09-15', '2026-08-15'] as $day) for ($i = 0; $i < 6; $i++) $this->record($user, $day, true);
        }
        foreach (['7d', '30d', '90d'] as $range) $this->getJson('/api/v1/admin/metrics/usage?range='.$range)->assertJsonPath('data.features.0.count', '<5');
        foreach ($this->getJson('/api/v1/admin/metrics/safety')->json('data') as $cell) $this->assertSame('<5', $cell['count']);
    }
    public function test_five_distinct_users_release_safe_counts(): void
    {
        foreach (range(1, 5) as $_) {
            $user = $this->consumer(); foreach (['2026-09-29', '2026-09-15', '2026-08-15'] as $day) $this->record($user, $day);
        }
        foreach (['7d' => 5, '30d' => 10, '90d' => 15] as $range => $count) $this->getJson('/api/v1/admin/metrics/usage?range='.$range)->assertJsonPath('data.features.0.count', $count);
        $days = $this->getJson('/api/v1/admin/metrics/overview')->json('data.daily_activity');
        $this->assertSame(5, collect($days)->firstWhere('date', '2026-09-29')['count']);
    }
    public function test_overlapping_ranges_hide_small_older_group(): void
    {
        foreach (range(1, 5) as $_) {
            $user = $this->consumer(); $this->record($user, '2026-09-29'); $this->record($user, '2026-08-15');
        }
        $this->record($this->consumer(), '2026-09-15');
        foreach (['7d', '30d', '90d'] as $range) $this->getJson('/api/v1/admin/metrics/usage?range='.$range)->assertJsonPath('data.features.0.count', '<5');
    }
    public function test_safety_requires_five_flagged_and_unflagged_contributors(): void
    {
        foreach (range(1, 5) as $_) foreach ([true, false] as $flag) {
            $user = $this->consumer(); foreach (['2026-09-29', '2026-09-15', '2026-08-15'] as $day) $this->record($user, $day, $flag);
        }
        $this->getJson('/api/v1/admin/metrics/safety')->assertJsonPath('data.0.count', 5)->assertJsonPath('data.1.count', 10)->assertJsonPath('data.2.count', 15);
    }
    public function test_safety_hides_small_complement_and_never_returns_content_or_identity(): void
    {
        foreach (range(1, 5) as $_) {
            $user = $this->consumer(); foreach (['2026-09-29', '2026-09-15', '2026-08-15'] as $day) $this->record($user, $day, true);
        }
        foreach (['2026-09-29', '2026-09-15', '2026-08-15'] as $day) $this->record($this->consumer(), $day);
        $response = $this->getJson('/api/v1/admin/metrics/safety')->assertJsonPath('data.0.count', '<5');
        foreach (['user_id', 'email', 'name', 'note', 'body', 'answers'] as $secret) $this->assertStringNotContainsString('"'.$secret.'"', $response->getContent());
        $this->assertStringNotContainsString('NEVER RETURN THIS', $response->getContent());
    }
    public function test_retention_uses_mature_cohorts_and_safe_complements(): void
    {
        foreach (range(1, 10) as $i) {
            $user = $this->consumer('2026-07-20');
            if ($i <= 5) foreach (['2026-07-21', '2026-07-27', '2026-08-19'] as $day) $this->record($user, $day);
        }
        $rows = $this->getJson('/api/v1/admin/metrics/retention')->json('data');
        $row = collect($rows)->firstWhere('week', '2026-07-20');
        $this->assertSame(['status' => 'available', 'count' => 5, 'cohort' => 10, 'rate' => 50], $row['retention']['d1']);
        $this->assertSame('immature', end($rows)['retention']['d30']['status']);
        $this->assertNull(end($rows)['retention']['d30']['cohort']);
    }
    public function test_retention_suppresses_denominators_rates_and_related_signup_totals(): void
    {
        foreach (range(1, 6) as $i) {
            $user = $this->consumer('2026-07-20');
            if ($i <= 5) foreach (['2026-07-21', '2026-07-27', '2026-08-19'] as $day) $this->record($user, $day);
        }
        $row = collect($this->getJson('/api/v1/admin/metrics/retention')->json('data'))->firstWhere('week', '2026-07-20');
        $this->assertSame('<5', $row['retention']['d1']['count']);
        $this->assertSame('<5', $row['retention']['d1']['cohort']);
        $this->assertNull($row['retention']['d1']['rate']);
        $this->assertSame('<5', $row['signups']['count']);
    }
    public function test_snapshot_freezes_edits_and_excludes_current_month_deleted_demo_and_admin_data(): void
    {
        foreach (range(1, 5) as $_) {
            $user = $this->consumer(); foreach (['2026-09-29', '2026-09-15', '2026-08-15'] as $day) $this->record($user, $day);
        }
        $first = $this->getJson('/api/v1/admin/metrics/usage?range=7d')->json();
        DB::table('mood_entries')->delete();
        $this->assertSame($first, $this->getJson('/api/v1/admin/metrics/usage?range=7d')->json());
        Cache::flush();
        DB::table('admin_metric_releases')->delete(); // Isolated fixture reset; never an operations procedure.
        foreach (['current', 'demo', 'admin', 'deleted'] as $kind) {
            $user = $this->consumer();
            if ($kind === 'demo') $user->forceFill(['is_demo' => true])->save();
            if ($kind === 'admin') $user->forceFill(['role' => 'admin'])->save();
            foreach (['2026-09-29', '2026-09-15', '2026-08-15'] as $day) $this->record($user, $kind === 'current' ? '2026-10-02' : $day);
            if ($kind === 'deleted') DB::table('mood_entries')->where('user_id', $user->id)->update(['is_deleted' => true]);
        }
        $this->getJson('/api/v1/admin/metrics/usage')->assertJsonPath('data.features.0.count', '<5');
    }
    public function test_one_actor_many_records_cannot_publish_a_feature(): void
    {
        $user = $this->consumer();
        foreach (['2026-09-29', '2026-09-15', '2026-08-15'] as $day) for ($i = 0; $i < 20; $i++) $this->record($user, $day);
        $this->getJson('/api/v1/admin/metrics/usage?range=90d')->assertJsonPath('data.features.0.count', '<5');
    }
    public function test_retention_joint_patterns_cannot_expose_small_cross_horizon_differences(): void
    {
        foreach (range(1, 10) as $i) {
            $user = $this->consumer('2026-07-20');
            if ($i <= 5) $this->record($user, '2026-07-21');
            if ($i >= 2 && $i <= 6) $this->record($user, '2026-07-27');
            if ($i <= 5) $this->record($user, '2026-08-19');
        }
        $row = collect($this->getJson('/api/v1/admin/metrics/retention')->json('data'))->firstWhere('week', '2026-07-20');
        foreach ($row['retention'] as $cell) { $this->assertSame('<5', $cell['count']); $this->assertNull($cell['rate']); }
    }
    public function test_database_release_survives_cache_clear_without_identity_or_raw_counts(): void
    {
        foreach (range(1, 7) as $_) {
            $user = $this->consumer(); foreach (['2026-09-29', '2026-09-15', '2026-08-15'] as $day) $this->record($user, $day);
        }
        $before = $this->getJson('/api/v1/admin/metrics/usage?range=7d')->assertJsonPath('data.features.0.count', 5)->json();
        Cache::flush(); DB::table('mood_entries')->delete();
        $this->assertSame($before, $this->getJson('/api/v1/admin/metrics/usage?range=7d')->json());
        $stored = DB::table('admin_metric_releases')->first()->payload;
        foreach (['user_id', 'email', 'name', 'body', 'note', 'NEVER RETURN THIS'] as $secret) $this->assertStringNotContainsString('"'.$secret.'"', $stored);
    }
}
