<?php
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
uses(RefreshDatabase::class);

it('returns seeded version and maintenance settings', function () {
    $this->getJson('/api/v1/config')->assertOk()->assertJsonPath('latest_app_version', '1.0.0')
        ->assertJsonPath('maintenance_mode', false)->assertJsonPath('update_ios_url', null);
});

it('protects settings against anonymous ordinary and moderator users', function () {
    $this->putJson('/admin/settings/maintenance_mode', ['value' => true])->assertUnauthorized();
    $user = User::factory()->create(); Sanctum::actingAs($user);
    $this->putJson('/admin/settings/maintenance_mode', ['value' => true])->assertForbidden();
    $user->forceFill(['role' => 'moderator'])->save();
    $this->putJson('/admin/settings/maintenance_mode', ['value' => true])->assertForbidden();
});

it('lets admins update settings and keeps crisis data accessible', function () {
    $user = User::factory()->create(); $user->forceFill(['role' => 'admin'])->save(); Sanctum::actingAs($user);
    $this->getJson('/api/v1/config')->assertOk();
    $this->putJson('/admin/settings/maintenance_mode', ['value' => true])->assertOk();
    $this->getJson('/api/v1/config')->assertJsonPath('maintenance_mode', true);
    $this->getJson('/up')->assertJsonPath('maintenance_mode', true);
    \App\Models\SafetyPlan::create(['id' => (string) \Illuminate\Support\Str::uuid(), 'user_id' => $user->id]);
    $this->getJson('/api/v1/safety-plan')->assertOk();
});

it('validates supported setting keys and values', function (string $key, mixed $value) {
    $user = User::factory()->create(); $user->forceFill(['role' => 'admin'])->save(); Sanctum::actingAs($user);
    $this->putJson('/admin/settings/'.$key, ['value' => $value])->assertUnprocessable();
})->with([['unknown', true], ['min_app_version', 'invalid'], ['maintenance_mode', 'maybe'], ['update_ios_url', 'https://example.org'], ['update_android_url', 'javascript:alert(1)']]);

it('sets typed settings from artisan', function () {
    $this->artisan('settings:set', ['key' => 'maintenance_mode', 'value' => 'true'])->assertSuccessful();
    $this->artisan('settings:set', ['key' => 'min_app_version', 'value' => '1.0.1'])->assertSuccessful();
    $this->getJson('/api/v1/config')->assertJsonPath('maintenance_mode', true)->assertJsonPath('min_app_version', '1.0.1');
});
