<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsurePatient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class Phase16RouteContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_phase16_manifest_matches_real_actions_and_patient_auth_before_resource_lookups(): void
    {
        $contracts = json_decode(file_get_contents(base_path('docs/phase16-routes.json')), true, flags: JSON_THROW_ON_ERROR);
        foreach ($contracts as [$method, $path, $action]) {
            $uri = '/api/v1/'.$path;
            $route = app('router')->getRoutes()->match(Request::create($uri, $method));
            $actual = $route->getActionName();
            if (! str_contains($action, '@')) {
                $actual = str_replace('@__invoke', '', $actual);
            }
            $this->assertStringEndsWith($action, $actual, "$method $path matched an unexpected action");
            $middleware = $route->gatherMiddleware();
            $this->assertContains('auth:sanctum', $middleware, $path);
            $this->assertContains(EnsurePatient::class, $middleware, $path);
            $this->assertTrue(collect($middleware)->contains(fn ($value) => str_starts_with($value, 'throttle:')), $path);
            $this->json($method, $uri)->assertUnauthorized();
        }
    }

    public function test_dashboard_bridge_contracts_match_existing_backend_routes_and_guards(): void
    {
        $contracts = json_decode(file_get_contents(base_path('docs/dashboard-route-contracts.json')), true, flags: JSON_THROW_ON_ERROR);
        foreach ($contracts as [$method, $path, $action, $guard]) {
            $uri = '/api/v1/'.$path;
            $route = app('router')->getRoutes()->match(Request::create($uri, $method));
            $this->assertStringEndsWith($action, $route->getActionName(), "$method $path");
            $this->assertContains($guard, $route->gatherMiddleware(), $path);
            $this->json($method, $uri)->assertUnauthorized();
        }
    }
}
