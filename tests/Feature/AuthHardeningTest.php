<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Provider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AuthHardeningTest extends TestCase
{
    use RefreshDatabase;
    protected function setUp(): void { parent::setUp(); Mail::fake(); }
    private function login(User $user, array $extra = []): array
    {
        app('auth')->forgetGuards();
        return $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'Tr0ub4dor', ...$extra])->assertOk()->json('data');
    }
    private function user(): User { return User::factory()->create(['password' => 'Tr0ub4dor']); }

    public function test_refresh_rotates_tokens_rejects_replay_and_hashes_storage(): void
    {
        $this->freezeTime();
        $user = $this->user(); $pair = $this->login($user);
        $token = \Laravel\Sanctum\PersonalAccessToken::findToken($pair['token']);
        $this->assertSame(now()->addHour()->timestamp, $token->expires_at->timestamp);
        $this->assertDatabaseHas('refresh_tokens', ['token_hash' => hash('sha256', $pair['refresh_token'])]);
        $this->assertDatabaseMissing('refresh_tokens', ['token_hash' => $pair['refresh_token']]);
        $this->travel(61)->minutes();
        app('auth')->forgetGuards();
        $this->withToken($pair['token'])->getJson('/api/v1/me')->assertUnauthorized();
        $rotated = $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $pair['refresh_token']])->assertOk()->json('data');
        $this->assertNotSame($pair['refresh_token'], $rotated['refresh_token']);
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $pair['refresh_token']])->assertUnauthorized();
        app('auth')->forgetGuards();
        $this->withToken($rotated['token'])->getJson('/api/v1/me')->assertOk();
    }

    public function test_provider_refresh_is_role_isolated_and_expires_after_thirty_days(): void
    {
        $provider = Provider::create(['name' => 'Dr', 'email' => 'dr@test.example', 'password_hash' => 'Tr0ub4dor']);
        $pair = $this->postJson('/api/v1/provider/auth/login', ['email' => $provider->email, 'password' => 'Tr0ub4dor'])->assertOk()->json('data');
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $pair['refresh_token']])->assertUnauthorized();
        $this->travel(31)->days();
        $this->postJson('/api/v1/provider/auth/refresh', ['refresh_token' => $pair['refresh_token']])->assertUnauthorized();
    }

    public function test_revoked_or_disabled_refresh_cannot_restore_a_session(): void
    {
        $user = $this->user(); $pair = $this->login($user);
        $this->withToken($pair['token'])->postJson('/api/v1/auth/revoke-refresh', ['refresh_token' => $pair['refresh_token']])->assertOk();
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $pair['refresh_token']])->assertUnauthorized();
        $pair = $this->login($user); $user->update(['is_active' => false]);
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $pair['refresh_token']])->assertUnauthorized();
    }

    public function test_lockout_fires_after_five_failures_and_different_ip_does_not_bypass(): void
    {
        foreach (['auth/login', 'provider/auth/login', 'admin/auth/login'] as $index => $route) {
            $email = "unknown{$index}@example.test";
            for ($attempt = 0; $attempt < 4; $attempt++) {
                $this->withServerVariables(['REMOTE_ADDR' => "192.0.2.".($attempt + 1)])
                    ->postJson('/api/v1/'.$route, ['email' => $email, 'password' => 'wrong'])->assertUnprocessable();
            }
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.50'])->postJson('/api/v1/'.$route, ['email' => strtoupper($email), 'password' => 'wrong'])
                ->assertStatus(429)->assertHeader('Retry-After');
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.60'])->postJson('/api/v1/'.$route, ['email' => $email, 'password' => 'wrong'])->assertStatus(429);
        }
        $this->assertDatabaseCount('security_events', 15);
        $this->assertNotSame('unknown0@example.test', DB::table('security_events')->first()->email_hash);
    }

    public function test_lockout_clears_after_fifteen_minutes(): void
    {
        $user = $this->user();
        for ($i = 0; $i < 5; $i++) $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'wrong']);
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'Tr0ub4dor'])->assertStatus(429);
        $this->travel(16)->minutes();
        $this->login($user);
    }

    public function test_common_password_is_rejected_and_troubador_is_accepted(): void
    {
        foreach (['auth/register', 'provider/auth/register'] as $route) {
            $this->postJson('/api/v1/'.$route, ['name' => 'Test', 'email' => 'weak@example.test', 'password' => 'password123', 'password_confirmation' => 'password123'])
                ->assertUnprocessable()->assertJsonValidationErrors('password');
            $this->postJson('/api/v1/'.$route, ['name' => 'Test', 'email' => 'strong@example.test', 'password' => 'Tr0ub4dor', 'password_confirmation' => 'Tr0ub4dor'])->assertCreated();
        }
    }

    public function test_password_reset_via_forgot_flow_revokes_all_sessions(): void
    {
        $user = $this->user(); $pair = $this->login($user);
        DB::table('password_reset_tokens')->insert(['email' => $user->email, 'token' => Hash::make('123456'), 'created_at' => now(), 'expires_at' => now()->addMinutes(15)]);
        $this->postJson('/api/v1/auth/reset-password', ['email' => $user->email, 'token' => '123456', 'password' => 'Changed9!Strong'])->assertOk();
        $this->assertSame(0, $user->tokens()->count());
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $pair['refresh_token']])->assertUnauthorized();
    }

    public function test_password_change_from_settings_keeps_current_session(): void
    {
        $user = $this->user(); $first = $this->login($user); $other = $this->login($user);
        $this->withToken($first['token'])->postJson('/api/v1/me/password', ['current_password' => 'Tr0ub4dor', 'password' => 'Changed9!Strong', 'password_confirmation' => 'Changed9!Strong'])->assertOk();
        $this->assertSame(1, $user->tokens()->count());
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $other['refresh_token']])->assertUnauthorized();
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $first['refresh_token']])->assertOk();
    }

    public function test_session_list_and_revoke_are_owner_scoped_and_revoke_refresh(): void
    {
        $user = $this->user(); $pair = $this->login($user, ['device_name' => 'Phone']);
        $other = $this->user()->createToken('Other');
        $this->withToken($pair['token'])->getJson('/api/v1/me/sessions')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.device', 'Phone');
        $this->deleteJson('/api/v1/me/sessions/'.$other->accessToken->id)->assertNotFound();
        $token = \Laravel\Sanctum\PersonalAccessToken::findToken($pair['token']);
        $this->deleteJson('/api/v1/me/sessions/'.$token->id)->assertOk();
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $pair['refresh_token']])->assertUnauthorized();
    }

    public function test_logout_and_logout_all_revoke_refresh_as_well_as_access(): void
    {
        $user = $this->user(); $one = $this->login($user); $two = $this->login($user);
        $this->withToken($one['token'])->postJson('/api/v1/auth/logout')->assertOk();
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $one['refresh_token']])->assertUnauthorized();
        app('auth')->forgetGuards();
        $this->withToken($two['token'])->postJson('/api/v1/auth/logout-all')->assertOk();
        $this->assertSame(0, $user->tokens()->count());
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $two['refresh_token']])->assertUnauthorized();
    }

    public function test_new_device_email_only_for_verified_users_and_once_per_device(): void
    {
        $user = $this->user(); $user->forceFill(['email_verified_at' => now()])->save();
        $this->login($user, ['device_id' => 'test-device', 'device_name' => 'Phone']);
        $this->login($user, ['device_id' => 'test-device']);
        Mail::assertQueued(\App\Mail\NewDeviceLoginMail::class, 1);
        $user->forceFill(['email_verified_at' => null])->save();
        $this->login($user, ['device_id' => 'another-device']);
        Mail::assertQueued(\App\Mail\NewDeviceLoginMail::class, 1);
    }
}
