<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\CommunityAvailability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class DevelopmentTestAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['development_access.enabled' => true,
            'development_access.railway_project_id' => CommunityAvailability::DEVELOPMENT_PROJECT,
            'development_access.railway_environment_id' => CommunityAvailability::DEVELOPMENT_ENVIRONMENT,
            'app.url' => 'https://api-dev.cozyhealth.org', 'launch.billing_enabled' => false]);
    }

    private function authenticate(User $user, ?array $abilities = null): void
    {
        app('auth')->forgetGuards();
        $this->withToken($user->createToken($abilities === null ? 'development-phone' : 'cozy-admin-management-v1', $abilities ?? ['*'], now()->addMinutes(30))->plainTextToken);
    }

    private function admin(array $abilities = ['admin:subscriptions']): User
    {
        $admin = User::factory()->create(['role' => 'admin']); $this->authenticate($admin, $abilities); return $admin;
    }

    private function body(User $user, string $plan = 'premium', int $days = 7): array
    {
        return ['email' => $user->email, 'plan' => $plan, 'duration_days' => $days, 'reason' => 'Approved development feature testing'];
    }

    public function test_scoped_admin_can_grant_each_tier_without_creating_a_purchase_or_student_approval(): void
    {
        foreach (['student', 'standard', 'premium'] as $tier) {
            $consumer = User::factory()->create(); $admin = $this->admin();
            $response = $this->postJson('/api/v1/admin/development-access', $this->body($consumer, $tier))->assertCreated()
                ->assertJsonPath('development_only', true)->assertJsonPath('data.plan', $tier);
            $id = $response->json('data.id');
            $this->assertDatabaseHas('admin_audit_logs', ['actor_id' => $admin->id, 'resource' => 'development-access', 'resource_id' => $id, 'action' => 'grant-test-access']);
            $this->assertNotSame('Approved development feature testing', DB::table('development_access_grants')->where('id', $id)->value('reason'));
            $this->authenticate($consumer);
            $this->getJson('/api/v1/subscription')->assertOk()->assertJsonPath('data.plan', $tier)
                ->assertJsonPath('data.is_development_test', true)->assertJsonMissingPath('data.reason');
        }
        $this->assertDatabaseCount('store_purchases', 0);
        $this->assertDatabaseCount('subscription_statuses', 0);
        $this->assertDatabaseCount('student_applications', 0);
        $this->assertFalse(config('launch.billing_enabled'));
    }

    public function test_grants_expire_and_cannot_affect_another_account(): void
    {
        $consumer = User::factory()->create(); $this->admin();
        $this->postJson('/api/v1/admin/development-access', $this->body($consumer, 'premium', 1))->assertCreated();
        $this->authenticate(User::factory()->create());
        $this->getJson('/api/v1/subscription')->assertOk()->assertJsonPath('data.status', 'none');
        $this->travel(1)->days(); $this->authenticate($consumer);
        $this->getJson('/api/v1/subscription')->assertOk()->assertJsonPath('data.status', 'none');
    }

    public function test_revoke_restores_real_subscription_and_preserves_history(): void
    {
        $consumer = User::factory()->create();
        $realId = (string) Str::uuid();
        DB::table('subscription_statuses')->insert(['id' => $realId, 'user_id' => $consumer->id, 'plan' => 'standard', 'status' => 'active', 'platform' => 'google', 'started_at' => now(), 'expires_at' => now()->addMonth(), 'created_at' => now(), 'updated_at' => now()]);
        $admin = $this->admin();
        $id = $this->postJson('/api/v1/admin/development-access', $this->body($consumer))->assertCreated()->json('data.id');
        $this->postJson('/api/v1/admin/development-access', $this->body($consumer))->assertConflict();
        $this->deleteJson('/api/v1/admin/development-access/'.$id)->assertOk();
        $this->deleteJson('/api/v1/admin/development-access/'.$id)->assertOk();
        $this->assertSame(1, DB::table('admin_audit_logs')->where('resource_id', $id)->where('action', 'revoke-test-access')->count());
        $this->assertDatabaseHas('development_access_grants', ['id' => $id, 'revoked_by' => $admin->id]);
        $this->authenticate($consumer);
        $this->getJson('/api/v1/subscription')->assertOk()->assertJsonPath('data.id', $realId)->assertJsonMissingPath('data.is_development_test');
    }

    public function test_non_admin_and_wrong_scope_cannot_manage_or_discover_grants(): void
    {
        $consumer = User::factory()->create(); $this->authenticate($consumer);
        $this->postJson('/api/v1/admin/development-access', $this->body($consumer))->assertForbidden();
        $this->admin(['admin:content']);
        $this->getJson('/api/v1/admin/development-access')->assertForbidden();
        $this->postJson('/api/v1/admin/development-access', $this->body($consumer))->assertForbidden();
        $this->assertDatabaseCount('development_access_grants', 0);
    }

    public function test_guard_fails_closed_for_wrong_environment_project_or_host_even_when_enabled(): void
    {
        $consumer = User::factory()->create(); $admin = $this->admin();
        $this->postJson('/api/v1/admin/development-access', $this->body($consumer))->assertCreated();
        foreach ([['development_access.enabled', false], ['development_access.railway_project_id', 'another-project'],
            ['development_access.railway_environment_id', '9ffd3fd1-ae52-4cc7-903e-35b529a151ae'],
            ['app.url', 'https://api.cozyhealth.org'], ['app.url', 'https://user@api-dev.cozyhealth.org'],
            ['app.url', 'https://api-dev.cozyhealth.org?unexpected=1'], ['app.url', 'http://api-dev.cozyhealth.org']] as [$key, $value]) {
            $original = config($key); config([$key => $value]);
            $this->authenticate($admin, ['admin:subscriptions']);
            $this->getJson('/api/v1/admin/development-access')->assertNotFound();
            $this->postJson('/api/v1/admin/development-access', $this->body($consumer))->assertNotFound();
            $this->authenticate($consumer);
            $this->getJson('/api/v1/subscription')->assertOk()->assertJsonPath('data.status', 'none');
            config([$key => $original]);
        }
    }

    public function test_grant_validation_rejects_unbounded_access_and_non_consumers(): void
    {
        $consumer = User::factory()->create(); $admin = $this->admin();
        foreach ([0, 31] as $days) $this->postJson('/api/v1/admin/development-access', $this->body($consumer, 'premium', $days))->assertUnprocessable();
        $this->postJson('/api/v1/admin/development-access', $this->body($consumer, 'free'))->assertUnprocessable();
        $this->postJson('/api/v1/admin/development-access', $this->body($admin))->assertUnprocessable();
        $inactive = User::factory()->create(['is_active' => false]);
        $this->postJson('/api/v1/admin/development-access', $this->body($inactive))->assertUnprocessable();
        $this->assertDatabaseCount('development_access_grants', 0);
    }

    public function test_navigation_capability_is_computed_from_environment_and_subscription_permission(): void
    {
        $admin = $this->admin(['admin:metrics', 'admin:subscriptions']);
        $this->assertContains('admin:development-access', $this->getJson('/api/v1/admin/auth/session')->assertOk()->json('capabilities'));
        config(['development_access.enabled' => false]);
        $this->authenticate($admin, ['admin:metrics', 'admin:subscriptions', 'admin:development-access']);
        $this->assertNotContains('admin:development-access', $this->getJson('/api/v1/admin/auth/session')->assertOk()->json('capabilities'));
        config(['development_access.enabled' => true]);
        $this->authenticate($admin, ['admin:metrics', 'admin:content']);
        $this->assertNotContains('admin:development-access', $this->getJson('/api/v1/admin/auth/session')->assertOk()->json('capabilities'));
    }
}
