<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EnvironmentIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_fresh_production_schema_has_no_user_or_sample_content(): void
    {
        foreach (['users', 'providers', 'journals', 'quizzes', 'affirmations', 'student_applications', 'store_purchases'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), "Unexpected seeded data in {$table}");
        }
        $this->assertSame(4, DB::table('subscription_packages')->count());
        $this->assertSame(['premium', 'standard', 'student'], DB::table('subscription_packages')->where('is_active', true)->orderBy('tier')->pluck('tier')->all());
        $this->assertFalse((bool) DB::table('subscription_packages')->where('tier', 'free')->value('is_active'));
    }

    public function test_cors_only_allows_the_environment_dashboard(): void
    {
        config(['cors.allowed_origins' => ['https://dashboard.cozyhealth.org']]);
        $this->withHeaders(['Origin' => 'https://dashboard.cozyhealth.org', 'Access-Control-Request-Method' => 'GET'])
            ->options('/api/v1/config')->assertHeader('Access-Control-Allow-Origin', 'https://dashboard.cozyhealth.org');
        $response = $this->withHeaders(['Origin' => 'https://dashboard-dev.cozyhealth.org', 'Access-Control-Request-Method' => 'GET'])
            ->options('/api/v1/config');
        // A fixed production origin is also valid CORS: browsers reject it
        // when the requesting origin is development. Reflection or * is not.
        $this->assertNotSame('https://dashboard-dev.cozyhealth.org', $response->headers->get('Access-Control-Allow-Origin'));
        $this->assertNotSame('*', $response->headers->get('Access-Control-Allow-Origin'));
    }
}
