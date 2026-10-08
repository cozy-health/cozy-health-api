<?php

namespace Tests\Feature\Provider;

use App\Models\ProviderAccessLog;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

class ProviderAccessLogTest extends ProviderDataTestCase
{
    public function test_provider_sees_own_log(): void
    {
        $provider = $this->provider();
        $this->event($provider, User::factory()->create());
        $this->event($this->provider(), User::factory()->create());
        $this->asProvider($provider)->getJson('/api/v1/provider/access-log')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.provider_id', $provider->id);
    }

    public function test_patient_sees_own_access_log(): void
    {
        $user = User::factory()->create();
        $provider = $this->provider();
        $this->event($provider, $user);
        Sanctum::actingAs($user);
        $response = $this->getJson('/api/v1/me/provider-access-log')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.provider.name', $provider->name)->assertJsonPath('data.0.provider.practice', 'Test Practice')
            ->assertJsonPath('data.0.action', 'view')->assertJsonMissingPath('data.0.user_id')->assertJsonMissingPath('data.0.ip_address');
        $this->assertEqualsCanonicalizing(['id', 'provider', 'action', 'resource_type', 'created_at'], array_keys($response->json('data.0')));
    }

    public function test_patient_cannot_see_other_patients_log(): void
    {
        $user = User::factory()->create();
        $this->event($this->provider(), User::factory()->create());
        Sanctum::actingAs($user);
        $this->getJson('/api/v1/me/provider-access-log?user_id=1')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_log_filters_work(): void
    {
        $provider = $this->provider();
        $user = User::factory()->create();
        $expected = $this->event($provider, $user, ['action' => 'list', 'resource_type' => 'mood', 'created_at' => '2026-10-02 23:59:59']);
        $this->event($provider, $user, ['action' => 'list', 'resource_type' => 'mood', 'created_at' => '2026-10-01 00:00:00']);
        $this->event($provider, $user, ['action' => 'view', 'resource_type' => 'mood', 'created_at' => '2026-10-02 12:00:00']);
        $this->event($provider, $user, ['action' => 'list', 'resource_type' => 'note', 'created_at' => '2026-10-02 12:00:00']);
        $this->asProvider($provider)->getJson('/api/v1/provider/access-log?action=list&resource_type=mood&from=2026-10-02&to=2026-10-02')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $expected->id);
        $this->getJson('/api/v1/provider/access-log?per_page=2')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('total', 4);
        $this->getJson('/api/v1/provider/access-log?from=invalid')->assertUnprocessable();
    }

    public function test_log_endpoints_require_correct_identity(): void
    {
        $this->getJson('/api/v1/me/provider-access-log')->assertUnauthorized();
        $this->getJson('/api/v1/provider/access-log')->assertUnauthorized();
        $this->asProvider($this->provider())->getJson('/api/v1/me/provider-access-log')->assertUnauthorized();
    }

    private function event($provider, $user, array $attributes = []): ProviderAccessLog
    {
        return ProviderAccessLog::create([...['provider_id' => $provider->id, 'user_id' => $user->id,
            'action' => 'view', 'resource_type' => 'client', 'ip_address' => '127.0.0.1', 'created_at' => now()], ...$attributes]);
    }
}
