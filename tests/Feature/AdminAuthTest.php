<?php

namespace Tests\Feature;

use App\Models\Provider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class AdminAuthTest extends TestCase
{
    use RefreshDatabase;

    private function admin(array $attributes = []): User
    {
        $user = User::factory()->create(['password' => 'Fixture7!Strong', ...$attributes]);
        $user->forceFill(['role' => 'admin'])->save();
        return $user;
    }

    public function test_admin_login_issues_scoped_expiring_credential_without_identity(): void
    {
        $this->freezeTime();
        $admin = $this->admin();
        $response = $this->postJson('/api/v1/admin/auth/login', ['email' => strtoupper($admin->email), 'password' => 'Fixture7!Strong'])->assertOk();
        $token = PersonalAccessToken::findToken($response->json('token'));
        $this->assertSame(['admin:metrics'], $token->abilities);
        $this->assertSame(now()->addMinutes(30)->timestamp, $token->expires_at->timestamp);
        $response->assertJsonMissingPath('user')->assertJsonMissingPath('email')->assertHeader('Cache-Control', 'no-store, private');
        $this->withToken($response->json('token'))->getJson('/api/v1/admin/auth/session')->assertOk()->assertExactJson(['role' => 'admin']);
        foreach (['/api/v1/me', '/api/v1/mood-entries'] as $path) {
            $this->getJson($path)->assertForbidden();
        }
        $this->getJson('/api/v1/provider/clients')->assertUnauthorized();
        $this->putJson('/api/v1/admin/settings/test', ['value' => true])->assertForbidden();
    }

    public function test_patient_provider_disabled_demo_and_wrong_password_cannot_sign_in(): void
    {
        $patient = User::factory()->create(['password' => 'Fixture7!Strong']);
        $provider = Provider::create(['name' => 'Test', 'email' => 'provider@example.test', 'password_hash' => 'Fixture7!Strong']);
        foreach ([$patient, $provider, $this->admin(['is_active' => false]), $this->admin(['is_demo' => true])] as $account) {
            $this->postJson('/api/v1/admin/auth/login', ['email' => $account->email, 'password' => 'Fixture7!Strong'])->assertUnprocessable();
        }
        $admin = $this->admin();
        $this->postJson('/api/v1/admin/auth/login', ['email' => $admin->email, 'password' => 'wrong'])->assertUnprocessable();
    }

    public function test_other_credentials_and_web_sessions_do_not_grant_admin_session(): void
    {
        $admin = $this->admin();
        $this->withToken($admin->createToken('cozy-mobile')->plainTextToken)->getJson('/api/v1/admin/auth/session')->assertForbidden();
        app('auth')->forgetGuards();
        $provider = Provider::create(['name' => 'Test', 'email' => 'provider@example.test']);
        $this->withToken($provider->createToken('cozy-provider-dashboard')->plainTextToken)->getJson('/api/v1/admin/auth/session')->assertUnauthorized();
        app('auth')->forgetGuards();
        $this->actingAs($admin, 'web')->getJson('/api/v1/admin/auth/session')->assertForbidden();
    }

    public function test_expiry_logout_and_role_revocation_take_effect(): void
    {
        $admin = $this->admin();
        $credential = $admin->createToken('cozy-admin-dashboard', ['admin:metrics'], now()->addMinutes(30));
        $this->withToken($credential->plainTextToken)->postJson('/api/v1/admin/auth/logout')->assertOk();
        app('auth')->forgetGuards();
        $this->getJson('/api/v1/admin/auth/session')->assertUnauthorized();
        $expired = $admin->createToken('cozy-admin-dashboard', ['admin:metrics'], now()->subMinute());
        app('auth')->forgetGuards();
        $this->withToken($expired->plainTextToken)->getJson('/api/v1/admin/auth/session')->assertUnauthorized();
        $valid = $admin->createToken('cozy-admin-dashboard', ['admin:metrics'], now()->addMinutes(30));
        $admin->forceFill(['role' => 'user'])->save();
        app('auth')->forgetGuards();
        $this->withToken($valid->plainTextToken)->getJson('/api/v1/admin/auth/session')->assertForbidden();
    }

    public function test_login_is_throttled_and_public_registration_cannot_assign_role(): void
    {
        for ($i = 0; $i < 10; $i++) $this->postJson('/api/v1/admin/auth/login', ['email' => 'unknown'.$i.'@example.test', 'password' => 'wrong'])->assertUnprocessable();
        $this->postJson('/api/v1/admin/auth/login', ['email' => 'unknown@example.test', 'password' => 'wrong'])->assertStatus(429);
        \Illuminate\Support\Facades\Mail::fake();
        \Illuminate\Support\Facades\Cache::flush();
        $this->postJson('/api/v1/auth/register', ['name' => 'Test', 'email' => 'role@example.test', 'password' => 'Fixture7!Strong', 'password_confirmation' => 'Fixture7!Strong', 'role' => 'admin'])->assertCreated();
        $this->assertSame('user', User::where('email', 'role@example.test')->first()->role);
    }
}
