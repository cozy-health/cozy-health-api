<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Analytics\PublishedComparison;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminRefinementTest extends TestCase
{
    use RefreshDatabase;

    private function signIn(array $abilities = ['admin:metrics', 'admin:moderation']): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        app('auth')->forgetGuards();
        $this->withToken($user->createToken('cozy-admin-management-v1', $abilities, now()->addMinutes(30))->plainTextToken);
    }

    private function report(array $extra = []): int
    {
        return DB::table('community_reports')->insertGetId([...['target_type' => 'post', 'target_id' => Str::uuid(), 'reason' => 'spam', 'snapshot' => '{"user_id":123,"body":"private"}', 'due_at' => now(), 'created_at' => now()->subHours(2), 'updated_at' => now()], ...$extra]);
    }

    public function test_comparisons_use_only_previous_stored_release_and_do_not_publish_suppressed_values(): void
    {
        $current = ['meta' => ['release' => '2026-10-01'], 'ranges' => ['30d' => ['summary' => [
            'new_users' => ['status' => 'available', 'count' => 30], 'active_users' => ['status' => 'suppressed', 'count' => '<5'], 'zero' => ['status' => 'available', 'count' => 5], 'hidden_before' => ['status' => 'available', 'count' => 10]]]]];
        $prior = ['ranges' => ['30d' => ['summary' => [
            'new_users' => ['status' => 'available', 'count' => 20], 'active_users' => ['status' => 'available', 'count' => 15], 'zero' => ['status' => 'available', 'count' => 0], 'hidden_before' => ['status' => 'suppressed', 'count' => '<5']]]]];
        $service = app(PublishedComparison::class);
        $this->assertSame('unavailable', $service->make('insights', $current, '30d')['status']);
        $this->assertDatabaseCount('admin_metric_releases', 0);
        DB::table('admin_metric_releases')->insert(['release_key' => 'admin-insights:v1:2026-09', 'payload' => json_encode($prior), 'created_at' => now()]);
        $comparison = $service->make('insights', $current, '30d');
        $this->assertEquals(50, $comparison['metrics']['new_users']['change_percent']);
        $this->assertNull($comparison['metrics']['active_users']['previous_count']);
        $this->assertNull($comparison['metrics']['hidden_before']['change_percent']);
        $this->assertNull($comparison['metrics']['zero']['change_percent']);
        $this->assertSame(['from' => '2026-08-02', 'to_exclusive' => '2026-09-01'], $comparison['previous_window']);
        $this->assertDatabaseCount('admin_metric_releases', 1);
    }

    public function test_details_ignore_pagination_and_do_not_return_report_snapshots_or_author_ids(): void
    {
        $this->signIn();
        $id = $this->report();
        $this->getJson('/api/v1/admin/reports/'.$id)->assertOk()->assertJsonPath('data.target', null)->assertJsonMissingPath('data.snapshot')->assertJsonMissingPath('data.reporter_id')->assertJsonMissingPath('data.target_id');
        $this->getJson('/api/v1/admin/support/9999')->assertNotFound();
        $this->signIn(['admin:content']);
        $this->getJson('/api/v1/admin/reports/'.$id)->assertForbidden();
        $this->getJson('/api/v1/admin/support/9999')->assertForbidden();
    }

    public function test_resolution_timestamp_survives_note_edits_clears_on_reopening_and_rejects_stale_updates(): void
    {
        $this->travelTo(now()->startOfSecond());
        $this->signIn();
        $user = User::factory()->create();
        $id = DB::table('support_reports')->insertGetId(['user_id' => $user->id, 'subject' => 'Help', 'message' => 'Local only', 'status' => 'open', 'created_at' => now()->subHours(2), 'updated_at' => now()]);
        $this->putJson('/api/v1/admin/support/'.$id, ['status' => 'resolved'])->assertOk();
        $resolved = DB::table('support_reports')->where('id', $id)->first();
        $this->travel(1)->minutes();
        $this->putJson('/api/v1/admin/support/'.$id, ['status' => 'resolved', 'admin_notes' => 'Updated note'])->assertOk();
        $this->assertDatabaseHas('support_reports', ['id' => $id, 'resolved_at' => $resolved->resolved_at]);
        $this->putJson('/api/v1/admin/support/'.$id, ['status' => 'open', 'expected_updated_at' => $resolved->updated_at])->assertConflict();
        $this->putJson('/api/v1/admin/support/'.$id, ['status' => 'open'])->assertOk();
        $this->assertDatabaseHas('support_reports', ['id' => $id, 'resolved_at' => null]);
        $report = $this->report();
        $this->putJson('/api/v1/admin/reports/'.$report, ['decision' => 'dismiss'])->assertOk();
        $this->assertNotNull(DB::table('community_reports')->where('id', $report)->value('resolved_at'));
    }

    public function test_queue_metrics_exclude_untimed_history_and_overdue_boundary_is_strict(): void
    {
        $this->travelTo(now()->startOfSecond());
        $this->signIn();
        $this->report(['due_at' => now()->subSecond()]);
        $this->report(['due_at' => now()]);
        $this->report(['status' => 'resolved', 'created_at' => now()->subHours(2), 'resolved_at' => now()->subHour()]);
        $this->report(['status' => 'resolved', 'created_at' => now()->subHours(4), 'resolved_at' => now()->subHour()]);
        $this->report(['status' => 'resolved', 'created_at' => now()->subDays(3)]);
        $this->getJson('/api/v1/admin/reports?overdue=1')->assertOk()->assertJsonCount(1, 'data.data');
        $this->getJson('/api/v1/admin/operations/summary')->assertOk()->assertJsonPath('data.queue.overdue_reports', 1)->assertJsonPath('data.queue.resolution.reports.median_seconds', 7200)->assertJsonPath('data.queue.resolution.reports.sample_count', 2);
        $this->getJson('/api/v1/admin/reports?overdue=wrong')->assertUnprocessable();
        $this->signIn(['admin:metrics']);
        $this->getJson('/api/v1/admin/operations/summary')->assertOk()->assertJsonMissingPath('data.queue');
    }

    public function test_support_version_detects_same_second_note_edits(): void
    {
        $this->travelTo(now()->startOfSecond());
        $this->signIn();
        $user = User::factory()->create();
        $id = DB::table('support_reports')->insertGetId(['user_id' => $user->id, 'subject' => 'Help', 'message' => 'Test', 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
        $version = $this->getJson('/api/v1/admin/support/'.$id)->assertOk()->json('data.version');
        $this->putJson('/api/v1/admin/support/'.$id, ['status' => 'open', 'admin_notes' => 'First edit', 'expected_version' => $version])->assertOk();
        $this->putJson('/api/v1/admin/support/'.$id, ['status' => 'open', 'admin_notes' => 'Stale edit', 'expected_version' => $version])->assertConflict();
        $this->assertDatabaseHas('support_reports', ['id' => $id, 'admin_notes' => 'First edit']);
    }
}
