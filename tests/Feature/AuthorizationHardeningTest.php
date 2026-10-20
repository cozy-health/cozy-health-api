<?php

namespace Tests\Feature;

use App\Models\Provider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthorizationHardeningTest extends TestCase
{
    use RefreshDatabase;

    private function patientToken(): string
    {
        return User::factory()->create()->createToken('device')->plainTextToken;
    }

    private function providerToken(): string
    {
        return Provider::create(['name' => 'Provider', 'email' => 'provider@example.test', 'password_hash' => 'Fixture7!Strong', 'is_active' => true])->createToken('device')->plainTextToken;
    }

    public function test_patient_token_rejected_by_provider_routes(): void
    {
        $this->withToken($this->patientToken())->getJson('/api/v1/provider/clients')->assertUnauthorized();
    }

    public function test_patient_token_rejected_by_admin_routes(): void
    {
        $this->withToken($this->patientToken())->getJson('/api/v1/admin/metrics/overview')->assertForbidden();
    }

    public function test_provider_token_rejected_by_patient_routes(): void
    {
        $this->withToken($this->providerToken())->getJson('/api/v1/journal-entries')->assertUnauthorized();
    }

    public function test_provider_token_rejected_by_admin_routes(): void
    {
        $this->withToken($this->providerToken())->getJson('/api/v1/admin/metrics/overview')->assertUnauthorized();
    }

    public function test_admin_token_cannot_read_patient_data_without_consent(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['role' => 'admin'])->save();
        $this->withToken($admin->createToken('device')->plainTextToken)->getJson('/api/v1/journal-entries')->assertForbidden();
    }

    public function test_sensitive_mutation_routes_have_rate_limits(): void
    {
        foreach (['auth/login', 'auth/register', 'auth/forgot-password', 'provider/auth/login', 'provider/auth/register', 'admin/auth/login', 'sync/batch', 'provider/clients/{userId}/notes', 'provider/clients/{userId}/resources'] as $path) {
            $route = collect(app('router')->getRoutes())->first(fn ($route) => $route->uri() === 'api/v1/'.$path && in_array('POST', $route->methods()));
            $this->assertNotNull($route, $path);
            $this->assertTrue(collect($route->gatherMiddleware())->contains(fn ($middleware) => str_starts_with($middleware, 'throttle:')), $path);
        }
    }
}
