<?php

namespace Tests\Feature\Provider;

use App\Models\User;

class ProviderResourceCrudTest extends ProviderDataTestCase
{
    public function test_provider_can_create_list_update_and_delete_own_resources(): void
    {
        $provider = $this->provider();
        $response = $this->asProvider($provider)->postJson('/api/v1/provider/resources', ['title' => 'Breathing exercise',
            'type' => 'exercise', 'duration_minutes' => 5, 'description' => 'A grounding exercise',
            'url' => 'https://example.test/breathe', 'provider_id' => 999])->assertCreated()->assertJsonPath('resource.provider_id', $provider->id);
        $id = $response->json('resource.id');
        $this->getJson('/api/v1/provider/resources')->assertOk()->assertJsonCount(1, 'data');
        $this->patchJson("/api/v1/provider/resources/$id", ['title' => 'Updated', 'description' => null])->assertOk()
            ->assertJsonPath('resource.title', 'Updated')->assertJsonPath('resource.description', null);
        $this->deleteJson("/api/v1/provider/resources/$id")->assertOk();
        $this->assertDatabaseMissing('provider_resources', ['id' => $id]);
    }

    public function test_library_and_mutations_are_provider_scoped(): void
    {
        $other = $this->provider();
        $resource = $other->resources()->create(['title' => 'Other resource', 'type' => 'article']);
        $this->asProvider($this->provider())->getJson('/api/v1/provider/resources')->assertOk()->assertJsonCount(0, 'data');
        $this->patchJson("/api/v1/provider/resources/{$resource->id}", ['title' => 'Stolen'])->assertNotFound();
        $this->deleteJson("/api/v1/provider/resources/{$resource->id}")->assertNotFound();
        $this->assertSame('Other resource', $resource->fresh()->title);
    }

    public function test_resource_validation_rejects_missing_fields_unsafe_urls_and_invalid_duration(): void
    {
        $this->asProvider($this->provider())->postJson('/api/v1/provider/resources', [])->assertUnprocessable();
        foreach (['javascript:alert(1)', 'file:///etc/passwd', 'ftp://example.test/resource'] as $url) {
            $this->postJson('/api/v1/provider/resources', ['title' => 'Resource', 'type' => 'article', 'url' => $url])->assertUnprocessable();
        }
        $this->postJson('/api/v1/provider/resources', ['title' => 'Resource', 'type' => 'article', 'duration_minutes' => -1])->assertUnprocessable();
        $this->postJson('/api/v1/provider/resources', ['title' => str_repeat('x', 256), 'type' => 'article'])->assertUnprocessable();
        $this->assertDatabaseCount('provider_resources', 0);
    }

    public function test_resource_pagination_is_bounded_and_requires_provider_authentication(): void
    {
        $this->getJson('/api/v1/provider/resources')->assertUnauthorized();
        $user = User::factory()->create();
        $this->withToken($user->createToken('patient')->plainTextToken)->getJson('/api/v1/provider/resources')->assertUnauthorized();
        $provider = $this->provider();
        foreach (['One', 'Two'] as $title) {
            $provider->resources()->create(['title' => $title, 'type' => 'article']);
        }
        $this->asProvider($provider)->getJson('/api/v1/provider/resources?per_page=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('total', 2);
        $this->getJson('/api/v1/provider/resources?per_page=101')->assertUnprocessable();
    }

    public function test_updates_and_cascaded_deletions_audit_affected_patient_assignments(): void
    {
        $provider = $this->provider();
        $user = User::factory()->create();
        $link = $this->link($provider, $user);
        $resource = $provider->resources()->create(['title' => 'Original', 'type' => 'exercise']);
        $assignment = $resource->assignments()->create(['provider_id' => $provider->id, 'user_id' => $user->id, 'assigned_at' => now()]);
        $this->asProvider($provider)->patchJson("/api/v1/provider/resources/{$resource->id}", ['title' => 'Updated'])->assertOk();
        $this->assertDatabaseHas('provider_access_logs', ['user_id' => $user->id, 'link_id' => $link->id, 'action' => 'update',
            'resource_type' => 'assignment', 'resource_id' => (string) $assignment->id]);
        $this->deleteJson("/api/v1/provider/resources/{$resource->id}")->assertOk();
        $this->assertDatabaseMissing('provider_assignments', ['id' => $assignment->id]);
        $this->assertDatabaseHas('provider_access_logs', ['user_id' => $user->id, 'action' => 'delete', 'resource_type' => 'assignment']);
    }
}
