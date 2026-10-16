<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminOverviewTest extends TestCase
{
    use RefreshDatabase;

    private function signIn(array $abilities, string $name = 'cozy-admin-management-v1'): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        app('auth')->forgetGuards();
        $this->withToken($admin->createToken($name, $abilities, now()->addMinutes(30))->plainTextToken);
    }

    public function test_summary_scopes_each_total_and_activity_without_identity_or_content(): void
    {
        User::factory()->count(2)->create(['role' => 'user', 'is_demo' => false]);
        User::factory()->create(['is_demo' => true]);
        $actor = User::factory()->create();
        DB::table('admin_audit_logs')->insert(['actor_id' => $actor->id, 'resource' => 'users', 'resource_id' => $actor->id, 'action' => 'update', 'changed_fields' => '["email"]', 'created_at' => now()]);
        DB::table('admin_audit_logs')->insert(['actor_id' => $actor->id, 'resource' => 'email', 'resource_id' => 'secret', 'action' => 'test-send', 'changed_fields' => '[]', 'created_at' => now()]);
        $this->signIn(['admin:metrics', 'admin:users']);
        $response = $this->getJson('/api/v1/admin/operations/summary')->assertOk()->assertJsonPath('data.totals.consumer_accounts', 3)->assertJsonMissingPath('data.totals.open_support')->assertJsonCount(1, 'data.activity');
        $this->assertStringNotContainsString('actor_id', $response->getContent());
        $this->assertStringNotContainsString('resource_id', $response->getContent());
        $this->getJson('/api/v1/admin/operations/summary?range=30d')->assertUnprocessable();
        $this->signIn(['admin:metrics'], 'cozy-admin-dashboard');
        $this->getJson('/api/v1/admin/operations/summary')->assertForbidden();
    }

    public function test_support_notes_survive_reopening_and_filters_are_validated(): void
    {
        $user = User::factory()->create();
        $id = DB::table('support_reports')->insertGetId(['user_id' => $user->id, 'subject' => 'Local request', 'message' => 'Test', 'admin_notes' => 'Keep this note', 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
        $this->signIn(['admin:moderation']);
        $this->getJson('/api/v1/admin/support?status=open')->assertOk()->assertJsonPath('data.data.0.admin_notes', 'Keep this note');
        $this->putJson('/api/v1/admin/support/'.$id, ['status' => 'resolved'])->assertOk();
        $this->getJson('/api/v1/admin/support?status=resolved')->assertOk()->assertJsonPath('data.data.0.admin_notes', 'Keep this note');
        $this->getJson('/api/v1/admin/support?status=invalid')->assertUnprocessable();
        $this->getJson('/api/v1/admin/reports?status=resolved')->assertOk()->assertJsonCount(0, 'data.data');
    }

    public function test_attention_orders_reports_and_subscriptions_exclude_expired_records(): void
    {
        $user = User::factory()->create();
        DB::table('subscription_statuses')->insert(['id' => Str::uuid(), 'user_id' => $user->id, 'status' => 'active', 'plan' => 'premium', 'expires_at' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);
        foreach ([2, 1] as $days) {
            DB::table('community_reports')->insert(['target_type' => 'post', 'target_id' => Str::uuid(), 'reason' => 'spam', 'snapshot' => '{}', 'due_at' => now()->addDays($days), 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->signIn(['admin:metrics', 'admin:moderation', 'admin:subscriptions']);
        $response = $this->getJson('/api/v1/admin/operations/summary')->assertOk()->assertJsonPath('data.totals.active_subscriptions', 0)->assertJsonPath('data.totals.pending_reports', 2);
        $this->assertLessThan($response->json('data.attention.1.due_at'), $response->json('data.attention.0.due_at'));
    }

    public function test_missing_report_content_can_only_be_dismissed(): void
    {
        $report = DB::table('community_reports')->insertGetId(['target_type' => 'post', 'target_id' => Str::uuid(), 'reason' => 'spam', 'snapshot' => '{}', 'due_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $this->signIn(['admin:moderation']);
        $this->getJson('/api/v1/admin/reports/'.$report.'/target')->assertOk()->assertJsonPath('data', null);
        $this->putJson('/api/v1/admin/reports/'.$report, ['decision' => 'publish', 'expected_version' => str_repeat('a', 64)])->assertConflict();
        $this->assertDatabaseHas('community_reports', ['id' => $report, 'status' => 'pending']);
        $this->putJson('/api/v1/admin/reports/'.$report, ['decision' => 'dismiss'])->assertOk();
        $this->putJson('/api/v1/admin/reports/'.$report, ['decision' => 'dismiss'])->assertConflict();
    }

    public function test_management_reads_do_not_exhaust_session_or_analytics_rate_limits(): void
    {
        $this->signIn(['admin:users', 'admin:metrics']);
        for ($i = 0; $i < 31; $i++) {
            $this->getJson('/api/v1/admin/users')->assertOk();
        }
        $this->getJson('/api/v1/admin/auth/session')->assertOk();
        $this->getJson('/api/v1/admin/metrics/usage')->assertOk();
        // The existing management limit is still enforced independently.
        for ($i = 31; $i < 60; $i++) {
            $this->getJson('/api/v1/admin/users')->assertOk();
        }
        $this->getJson('/api/v1/admin/users')->assertStatus(429);
        $this->getJson('/api/v1/admin/auth/session')->assertOk();
    }
}
