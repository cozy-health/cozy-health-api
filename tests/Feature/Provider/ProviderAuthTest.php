<?php

namespace Tests\Feature\Provider;

use App\Mail\ProviderPasswordResetMail;
use App\Mail\ProviderPasswordResetConfirmationMail;
use App\Models\Provider;
use App\Models\ProviderClientLink;
use App\Models\ProviderAssignment;
use App\Models\ProviderResource;
use App\Models\ProviderNote;
use App\Models\ProviderReport;
use App\Models\ProviderAccessLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ProviderAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function provider(array $attributes = []): Provider
    {
        return Provider::create([...[
            'name' => 'Dr Avery', 'email' => 'avery@example.com',
            'password_hash' => 'Fixture7!Strong', 'is_active' => true,
        ], ...$attributes]);
    }

    private function registration(array $attributes = []): array
    {
        return [...[
            'name' => 'Dr Avery', 'email' => 'avery@example.com',
            'password' => 'Fixture7!Strong', 'password_confirmation' => 'Fixture7!Strong',
        ], ...$attributes];
    }

    private function resetCode(Provider $provider, string $code = '123456', bool $expired = false): void
    {
        DB::table('provider_password_reset_tokens')->insert([
            'email' => $provider->email, 'token' => Hash::make($code),
            'created_at' => now(), 'expires_at' => $expired ? now()->subMinute() : now()->addMinutes(15),
        ]);
    }

    private function resetPayload(array $attributes = []): array
    {
        return [...[
            'email' => 'avery@example.com', 'token' => '123456',
            'password' => 'new-Fixture7!Strong', 'password_confirmation' => 'new-Fixture7!Strong',
        ], ...$attributes];
    }

    public function test_provider_can_register(): void
    {
        $response = $this->postJson('/api/v1/provider/auth/register', $this->registration([
            'is_verified' => true, 'is_active' => false, 'timezone' => 'Invalid/Zone',
        ]))->assertCreated()->assertJsonPath('success', true)
            ->assertJsonPath('data.provider.is_verified', false)
            ->assertJsonPath('data.provider.is_active', true)
            ->assertJsonPath('data.provider.timezone', 'UTC')
            ->assertJsonMissingPath('data.provider.password_hash')
            ->assertJsonMissingPath('data.provider.remember_token');
        $provider = Provider::firstOrFail();
        $this->assertTrue(Hash::check('Fixture7!Strong', $provider->password_hash));
        $this->assertDatabaseHas('personal_access_tokens', ['tokenable_type' => Provider::class, 'tokenable_id' => $provider->id]);
        $this->withToken($response->json('data.token'))->getJson('/api/v1/provider/me')->assertOk();
    }

    public function test_dashboard_token_expires_after_thirty_minutes(): void
    {
        $provider = $this->provider();
        $this->freezeTime();
        $response = $this->postJson('/api/v1/provider/auth/login', [
            'email' => $provider->email, 'password' => 'Fixture7!Strong',
            'device_name' => 'cozy-provider-dashboard',
        ])->assertOk();
        $token = \Laravel\Sanctum\PersonalAccessToken::findToken($response->json('data.token'));
        $this->assertSame(now()->addMinutes(30)->timestamp, $token->expires_at->timestamp);
        $this->withToken($response->json('data.token'))->getJson('/api/v1/provider/me')->assertOk();
        $this->travel(31)->minutes();
        // Sanctum must resolve the bearer token again rather than reusing the test guard.
        app('auth')->forgetGuards();
        $this->withToken($response->json('data.token'))->getJson('/api/v1/provider/me')->assertUnauthorized();
    }

    public function test_provider_register_requires_unique_email(): void
    {
        $this->provider();
        $this->postJson('/api/v1/provider/auth/register', $this->registration())->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_provider_register_requires_password_confirmation(): void
    {
        $this->postJson('/api/v1/provider/auth/register', $this->registration(['password_confirmation' => 'wrong']))->assertUnprocessable()->assertJsonValidationErrors('password');
    }

    public function test_license_number_is_unique_per_state(): void
    {
        $this->provider(['license_number' => '123', 'license_state' => 'CA']);
        $data = $this->registration(['email' => 'other@example.com', 'license_number' => '123', 'license_state' => 'ca']);
        $this->postJson('/api/v1/provider/auth/register', $data)->assertUnprocessable()->assertJsonValidationErrors('license_number');
        $this->postJson('/api/v1/provider/auth/register', [...$data, 'license_state' => 'NY'])->assertCreated();
    }

    public function test_provider_can_login(): void
    {
        $provider = $this->provider();
        $this->postJson('/api/v1/provider/auth/login', ['email' => 'AVERY@example.com', 'password' => 'Fixture7!Strong', 'device_name' => 'office'])
            ->assertOk()->assertJsonPath('data.provider.id', $provider->id)->assertJsonStructure(['success', 'message', 'data' => ['provider', 'token']]);
        $this->assertDatabaseHas('personal_access_tokens', ['tokenable_type' => Provider::class, 'name' => 'office']);
    }

    public function test_provider_login_rejects_wrong_password(): void
    {
        $this->provider();
        $this->postJson('/api/v1/provider/auth/login', ['email' => 'avery@example.com', 'password' => 'wrong'])->assertUnprocessable();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_provider_login_rejects_disabled_account(): void
    {
        $this->provider(['is_active' => false]);
        $this->postJson('/api/v1/provider/auth/login', ['email' => 'avery@example.com', 'password' => 'Fixture7!Strong'])->assertForbidden();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_legacy_provider_without_password_cannot_login(): void
    {
        $this->provider(['password_hash' => null]);
        $this->postJson('/api/v1/provider/auth/login', ['email' => 'avery@example.com', 'password' => 'Fixture7!Strong'])->assertUnprocessable();
    }

    public function test_provider_can_logout(): void
    {
        $provider = $this->provider();
        $token = $provider->createToken('office')->plainTextToken;
        $provider->createToken('other-device');
        $this->withToken($token)->postJson('/api/v1/provider/auth/logout')->assertOk();
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/provider/me')->assertUnauthorized();
    }

    public function test_provider_can_fetch_me(): void
    {
        $provider = $this->provider();
        $this->withToken($provider->createToken('office')->plainTextToken)->getJson('/api/v1/provider/me')
            ->assertOk()->assertJsonPath('data.provider.email', $provider->email)->assertJsonMissingPath('data.provider.password_hash');
    }

    public function test_provider_can_update_me(): void
    {
        $provider = $this->provider();
        $this->withToken($provider->createToken('office')->plainTextToken)->patchJson('/api/v1/provider/me', [
            'name' => 'Dr Updated', 'practice' => 'Cozy Clinic', 'timezone' => 'Africa/Lagos', 'is_verified' => true, 'email' => 'hijack@example.com',
        ])->assertOk()->assertJsonPath('data.provider.name', 'Dr Updated')->assertJsonPath('data.provider.timezone', 'Africa/Lagos')
            ->assertJsonPath('data.provider.is_verified', false)->assertJsonPath('data.provider.email', 'avery@example.com');
    }

    public function test_provider_profile_rejects_invalid_timezone(): void
    {
        $provider = $this->provider();
        $this->withToken($provider->createToken('office')->plainTextToken)->patchJson('/api/v1/provider/me', ['timezone' => 'Bad/Zone'])->assertUnprocessable();
    }

    public function test_disabled_provider_token_is_rejected_immediately(): void
    {
        $provider = $this->provider();
        $token = $provider->createToken('office')->plainTextToken;
        $provider->update(['is_active' => false]);
        $this->withToken($token)->getJson('/api/v1/provider/me')->assertForbidden();
    }

    public function test_provider_forgot_password_creates_reset(): void
    {
        $provider = $this->provider();
        $this->postJson('/api/v1/provider/auth/forgot-password', ['email' => $provider->email])->assertOk();
        $record = DB::table('provider_password_reset_tokens')->first();
        Mail::assertQueued(ProviderPasswordResetMail::class, function ($mail) use ($record, $provider) {
            return $mail->hasTo($provider->email) && Hash::check($mail->token, $record->token) && $mail->token !== $record->token;
        });
        $this->assertDatabaseCount('password_reset_tokens', 0);
        $this->assertEquals(now()->addMinutes(15)->timestamp, strtotime($record->expires_at));
    }

    public function test_forgot_password_does_not_disclose_unknown_or_disabled_accounts(): void
    {
        $this->provider(['is_active' => false]);
        $unknown = $this->postJson('/api/v1/provider/auth/forgot-password', ['email' => 'unknown@example.com'])->assertOk()->json();
        $disabled = $this->postJson('/api/v1/provider/auth/forgot-password', ['email' => 'avery@example.com'])->assertOk()->json();
        $this->assertSame($unknown, $disabled);
        Mail::assertNothingQueued();
        $this->assertDatabaseCount('provider_password_reset_tokens', 0);
    }

    public function test_provider_reset_password_changes_password(): void
    {
        $provider = $this->provider();
        $this->resetCode($provider);
        $provider->createToken('office');
        $this->postJson('/api/v1/provider/auth/reset-password', $this->resetPayload())->assertOk();
        $this->assertTrue(Hash::check('new-Fixture7!Strong', $provider->fresh()->password_hash));
        $this->assertDatabaseCount('provider_password_reset_tokens', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        Mail::assertQueued(ProviderPasswordResetConfirmationMail::class);
        $this->postJson('/api/v1/provider/auth/reset-password', $this->resetPayload())->assertStatus(400);
    }

    public function test_expired_reset_code_is_rejected(): void
    {
        $provider = $this->provider();
        $this->resetCode($provider, expired: true);
        $this->postJson('/api/v1/provider/auth/reset-password', $this->resetPayload())->assertStatus(400);
        $this->assertTrue(Hash::check('Fixture7!Strong', $provider->fresh()->password_hash));
    }

    public function test_wrong_reset_code_is_rejected(): void
    {
        $provider = $this->provider();
        $this->resetCode($provider);
        $this->postJson('/api/v1/provider/auth/reset-password', $this->resetPayload(['token' => '654321']))->assertStatus(400);
        $this->assertTrue(Hash::check('Fixture7!Strong', $provider->fresh()->password_hash));
    }

    public function test_reset_requires_password_confirmation(): void
    {
        $this->postJson('/api/v1/provider/auth/reset-password', $this->resetPayload(['password_confirmation' => 'wrong']))->assertUnprocessable();
    }

    public function test_patient_and_provider_password_resets_are_isolated(): void
    {
        $provider = $this->provider();
        $user = User::factory()->create(['email' => $provider->email, 'password' => 'patient-password']);
        DB::table('password_reset_tokens')->insert(['email' => $user->email, 'token' => Hash::make('654321'), 'created_at' => now(), 'expires_at' => now()->addMinutes(15)]);
        $this->postJson('/api/v1/provider/auth/reset-password', $this->resetPayload(['token' => '654321']))->assertStatus(400);
        $this->resetCode($provider);
        $this->postJson('/api/v1/auth/reset-password', $this->resetPayload())->assertStatus(400);
        $this->postJson('/api/v1/provider/auth/reset-password', $this->resetPayload())->assertOk();
        $this->assertTrue(Hash::check('patient-password', $user->fresh()->password));
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $user->email]);
    }

    public function test_provider_register_is_throttled(): void
    {
        for ($i = 0; $i < 5; $i++) { $this->postJson('/api/v1/provider/auth/register', [])->assertUnprocessable(); }
        $this->postJson('/api/v1/provider/auth/register', [])->assertStatus(429);
    }

    public function test_provider_login_is_throttled(): void
    {
        for ($i = 0; $i < 10; $i++) { $this->postJson('/api/v1/provider/auth/login', [])->assertUnprocessable(); }
        $this->postJson('/api/v1/provider/auth/login', [])->assertStatus(429);
    }

    public function test_user_token_cannot_access_provider_routes(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('mobile')->plainTextToken;
        $this->withToken($token)->getJson('/api/v1/provider/me')->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->patchJson('/api/v1/provider/me', ['name' => 'hijack'])->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->postJson('/api/v1/provider/auth/logout')->assertUnauthorized();
    }

    public function test_provider_token_cannot_access_user_routes(): void
    {
        $provider = $this->provider();
        $token = $provider->createToken('office')->plainTextToken;
        $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/provider')->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertUnauthorized();
    }

    public function test_patient_web_session_cannot_access_provider_routes(): void
    {
        $this->actingAs(User::factory()->create(), 'web')->getJson('/api/v1/provider/me')->assertUnauthorized();
    }

    public function test_provider_routes_require_authentication(): void
    {
        $this->getJson('/api/v1/provider/me')->assertUnauthorized();
        $this->patchJson('/api/v1/provider/me', ['name' => 'test'])->assertUnauthorized();
        $this->postJson('/api/v1/provider/auth/logout')->assertUnauthorized();
    }

    public function test_make_provider_command_creates_verified_account(): void
    {
        $this->artisan('user:make-provider', ['email' => 'doctor@example.com', 'name' => 'Dr Avery', 'practice' => 'Cozy Clinic', 'license_number' => '123', 'license_state' => 'CA'])->assertExitCode(0);
        $provider = Provider::where('email', 'doctor@example.com')->firstOrFail();
        $this->assertTrue($provider->is_verified);
        $this->assertTrue($provider->is_active);
        $this->assertTrue(Hash::isHashed($provider->password_hash));
        $this->artisan('user:make-provider', ['email' => 'doctor@example.com', 'name' => 'Duplicate', 'practice' => 'Cozy Clinic', 'license_number' => '456', 'license_state' => 'CA'])->assertExitCode(1);
        $this->assertDatabaseCount('providers', 1);
    }

    public function test_schema_defaults_relationships_and_patient_deletion(): void
    {
        $provider = $this->provider();
        $user = User::factory()->create();
        $link = ProviderClientLink::create(['provider_id' => $provider->id, 'user_id' => $user->id, 'linked_at' => now()])->refresh();
        foreach (['moods', 'journals', 'quizzes', 'notes'] as $type) { $this->assertFalse($link->{'consent_'.$type}); }
        $this->assertSame($provider->id, $link->provider->id);
        $this->assertSame($user->id, $link->user->id);
        $this->assertCount(0, $provider->clientLinks()->active()->get());
        $link->update(['verified_at' => now()]);
        $this->assertCount(1, $provider->clientLinks()->active()->get());
        $link->update(['revoked_at' => now()]);
        $this->assertCount(0, $provider->clientLinks()->active()->get());
        $resource = $provider->resources()->create(['title' => 'Breathing', 'type' => 'exercise']);
        $assignment = $resource->assignments()->create(['provider_id' => $provider->id, 'user_id' => $user->id, 'assigned_at' => now()])->refresh();
        $this->assertSame('assigned', $assignment->status);
        $this->assertSame($resource->id, $assignment->resource->id);
        $user->providerNotes()->create(['provider_id' => $provider->id, 'body' => 'Note']);
        $user->providerReports()->create(['provider_id' => $provider->id, 'type' => 'summary', 'period_start' => '2026-10-01', 'period_end' => '2026-10-08', 'file_path' => 'private/test.pdf', 'generated_at' => now()]);
        $provider->accessLogs()->create(['user_id' => $user->id, 'action' => 'view', 'resource_type' => 'note']);
        $this->assertCount(1, $user->providerClientLinks);
        $this->assertCount(1, $user->providerAssignments);
        $this->assertCount(1, $provider->notes);
        $this->assertCount(1, $provider->reports);
        $user->forceDelete();
        $this->assertDatabaseCount('provider_client_links', 0);
        $this->assertDatabaseCount('provider_assignments', 0);
        $this->assertDatabaseCount('provider_notes', 0);
        $this->assertDatabaseCount('provider_reports', 0);
        $this->assertDatabaseHas('provider_access_logs', ['user_id' => null]);
    }
}
