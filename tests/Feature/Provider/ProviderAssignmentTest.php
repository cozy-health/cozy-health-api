<?php

namespace Tests\Feature\Provider;

use App\Models\ProviderAssignment;
use App\Models\User;

class ProviderAssignmentTest extends ProviderDataTestCase
{
    public function test_provider_assigns_lists_and_removes_resource_with_audit(): void
    {
        $provider = $this->provider();
        $user = User::factory()->create();
        $link = $this->link($provider, $user);
        $resource = $provider->resources()->create(['title' => 'Breathing', 'type' => 'exercise']);
        $path = "/api/v1/provider/clients/{$user->id}/resources";
        $assignment = $this->asProvider($provider)->postJson($path, ['resource_id' => $resource->id, 'notes' => 'Try each morning'])->assertCreated()
            ->assertJsonPath('assignment.status', 'assigned')->json('assignment');
        $this->getJson($path)->assertOk()->assertJsonPath('data.0.resource.title', 'Breathing');
        $this->deleteJson($path.'/'.$assignment['id'])->assertOk();
        $this->assertDatabaseMissing('provider_assignments', ['id' => $assignment['id']]);
        foreach (['create', 'list', 'delete'] as $action) {
            $this->assertDatabaseHas('provider_access_logs', ['provider_id' => $provider->id, 'user_id' => $user->id,
                'link_id' => $link->id, 'action' => $action, 'resource_type' => 'assignment']);
        }
    }

    public function test_assignments_require_active_link_and_own_resource(): void
    {
        $provider = $this->provider();
        $other = $this->provider();
        $user = User::factory()->create();
        $resource = $provider->resources()->create(['title' => 'Own', 'type' => 'article']);
        $foreign = $other->resources()->create(['title' => 'Foreign', 'type' => 'article']);
        $path = "/api/v1/provider/clients/{$user->id}/resources";
        $this->asProvider($provider)->postJson($path, ['resource_id' => $resource->id])->assertNotFound();
        $link = $this->link($provider, $user, ['verified_at' => null]);
        $this->getJson($path)->assertNotFound();
        $link->update(['verified_at' => now()]);
        $this->postJson($path, ['resource_id' => $foreign->id])->assertNotFound();
        $this->postJson($path, ['resource_id' => $resource->id, 'notes' => str_repeat('x', 5001)])->assertUnprocessable();
        $link->update(['revoked_at' => now()]);
        $this->postJson($path, ['resource_id' => $resource->id])->assertNotFound();
        $this->assertDatabaseCount('provider_assignments', 0);
        $this->assertDatabaseCount('provider_access_logs', 0);
    }

    public function test_provider_cannot_read_or_remove_another_providers_assignment(): void
    {
        $provider = $this->provider();
        $other = $this->provider();
        $user = User::factory()->create();
        $this->link($provider, $user);
        $this->link($other, $user);
        $resource = $other->resources()->create(['title' => 'Private', 'type' => 'article']);
        $assignment = ProviderAssignment::create(['provider_id' => $other->id, 'user_id' => $user->id,
            'resource_id' => $resource->id, 'assigned_at' => now()]);
        $path = "/api/v1/provider/clients/{$user->id}/resources";
        $this->asProvider($provider)->getJson($path)->assertOk()->assertJsonCount(0, 'data');
        $this->deleteJson($path.'/'.$assignment->id)->assertNotFound();
        $this->assertDatabaseHas('provider_assignments', ['id' => $assignment->id]);
    }

    public function test_patient_sees_only_own_active_assignments_and_can_complete_or_decline(): void
    {
        $provider = $this->provider();
        $user = User::factory()->create();
        $other = User::factory()->create();
        $link = $this->link($provider, $user);
        $this->link($provider, $other);
        $resource = $provider->resources()->create(['title' => 'Breathing', 'type' => 'exercise']);
        $assignment = ProviderAssignment::create(['provider_id' => $provider->id, 'user_id' => $user->id, 'resource_id' => $resource->id, 'assigned_at' => now()]);
        $foreign = ProviderAssignment::create(['provider_id' => $provider->id, 'user_id' => $other->id, 'resource_id' => $resource->id, 'assigned_at' => now()]);
        $this->withToken($user->createToken('patient')->plainTextToken)->getJson('/api/v1/me/resources')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.provider.name', $provider->name);
        $this->patchJson('/api/v1/me/resources/'.$foreign->id, ['status' => 'completed'])->assertNotFound();
        $path = '/api/v1/me/resources/'.$assignment->id;
        $this->patchJson($path, ['status' => 'completed'])->assertOk()->assertJsonPath('assignment.status', 'completed');
        $this->assertNotNull($assignment->fresh()->completed_at);
        $this->patchJson($path, ['status' => 'declined'])->assertOk()->assertJsonPath('assignment.completed_at', null);
        $this->patchJson($path, ['status' => 'assigned'])->assertUnprocessable();
        $link->update(['revoked_at' => now()]);
        $this->getJson('/api/v1/me/resources')->assertOk()->assertJsonCount(0, 'data');
        $this->patchJson($path, ['status' => 'completed'])->assertNotFound();
    }

    public function test_patient_routes_require_patient_authentication(): void
    {
        $this->getJson('/api/v1/me/resources')->assertUnauthorized();
        $this->asProvider($this->provider())->getJson('/api/v1/me/resources')->assertUnauthorized();
    }
}
