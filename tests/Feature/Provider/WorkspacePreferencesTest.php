<?php

namespace Tests\Feature\Provider;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class WorkspacePreferencesTest extends ProviderDataTestCase
{
    public function test_defaults_are_read_only_and_private(): void
    {
        $this->asProvider($this->provider())->getJson('/api/v1/provider/workspace-preferences')
            ->assertOk()->assertJsonPath('data.theme', 'system')
            ->assertJsonPath('data.layout', 'overview')->assertJsonPath('data.tour_state', 'new');
        $this->assertDatabaseCount('workspace_preferences', 0);
    }

    public function test_preferences_and_tour_progress_are_account_owned_and_partial_updates_merge(): void
    {
        $first = $this->provider(); $other = $this->provider();
        $this->asProvider($first)->patchJson('/api/v1/provider/workspace-preferences', [
            'theme' => 'dark', 'accent' => 'teal', 'tour_state' => 'dismissed', 'tour_step' => 2,
        ])->assertOk()->assertJsonPath('data.accent', 'teal');
        $this->asProvider($first)->patchJson('/api/v1/provider/workspace-preferences', ['layout' => 'focus'])
            ->assertOk()->assertJsonPath('data.theme', 'dark')->assertJsonPath('data.tour_step', 2);
        app('auth')->forgetGuards();
        $this->asProvider($other)->getJson('/api/v1/provider/workspace-preferences')
            ->assertOk()->assertJsonPath('data.theme', 'system')->assertJsonPath('data.tour_step', 0);
        app('auth')->forgetGuards();
        $this->asProvider($first)->getJson('/api/v1/provider/workspace-preferences')
            ->assertOk()->assertJsonPath('data.layout', 'focus');
    }

    public function test_identity_injection_and_invalid_values_are_rejected(): void
    {
        $this->asProvider($this->provider());
        foreach ([['provider_id' => 99], ['user_id' => 99], ['theme' => 'unknown'],
            ['layout' => 'unknown'], ['tour_step' => 4], ['tour_state' => 'approved'],
            ['tour_version' => 999], ['sidebar_collapsed' => 'yes'], []] as $patch) {
            $this->patchJson('/api/v1/provider/workspace-preferences', $patch)->assertUnprocessable();
        }
        $this->assertDatabaseCount('workspace_preferences', 0);
    }

    public function test_admin_and_provider_with_same_numeric_id_have_separate_preferences(): void
    {
        $provider = $this->provider();
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $this->asProvider($provider)->patchJson('/api/v1/provider/workspace-preferences', ['accent' => 'violet'])->assertOk();
        app('auth')->forgetGuards();
        $this->withToken($admin->createToken('admin')->plainTextToken)
            ->patchJson('/api/v1/admin/workspace-preferences', ['theme' => 'light'])
            ->assertOk()->assertJsonPath('data.accent', 'blue');
        app('auth')->forgetGuards();
        $this->asProvider($provider)->getJson('/api/v1/provider/workspace-preferences')
            ->assertOk()->assertJsonPath('data.accent', 'violet')->assertJsonPath('data.theme', 'system');
        $this->assertDatabaseCount('workspace_preferences', 2);
    }

    public function test_patient_and_guest_cannot_access_admin_preferences(): void
    {
        $this->getJson('/api/v1/admin/workspace-preferences')->assertUnauthorized();
        $this->getJson('/api/v1/provider/workspace-preferences')->assertUnauthorized();
        $user = User::factory()->create();
        $this->withToken($user->createToken('patient')->plainTextToken)
            ->patchJson('/api/v1/admin/workspace-preferences', ['theme' => 'dark'])->assertForbidden();
        $this->assertDatabaseCount('workspace_preferences', 0);
    }

    public function test_account_removal_cascades_preferences(): void
    {
        $provider = $this->provider();
        $this->asProvider($provider)->patchJson('/api/v1/provider/workspace-preferences', ['density' => 'compact'])->assertOk();
        DB::table('providers')->where('id', $provider->id)->delete();
        $this->assertDatabaseCount('workspace_preferences', 0);
    }
}
