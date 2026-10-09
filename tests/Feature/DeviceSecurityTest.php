<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DeviceSecurityTest extends TestCase
{
    use RefreshDatabase;
    public function test_production_does_not_expose_package_debug_or_documentation_routes(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        foreach (['/api/documentation', '/docs', '/docs/asset/swagger-ui-bundle.js', '/telescope'] as $path) {
            $this->get($path)->assertNotFound();
        }
    }
    public function test_device_warning_is_count_only_without_user_device_or_ip_rows(): void
    {
        $this->actingAs(User::factory()->create())->postJson('/api/v1/me/device-security', ['detected' => true, 'device_id' => 'ignored'])->assertOk();
        $this->postJson('/api/v1/me/device-security', ['detected' => true])->assertOk();
        $this->assertDatabaseHas('security_signal_counts', ['count' => 2, 'event' => 'device_integrity_warning']);
        $this->assertSame(['day', 'event', 'count'], Schema::getColumnListing('security_signal_counts'));
    }
}
