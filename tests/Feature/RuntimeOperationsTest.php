<?php

namespace Tests\Feature;

use App\Jobs\RuntimeQueueProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Bus, Cache, Http};
use Tests\TestCase;

class RuntimeOperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_probe_is_a_database_job_and_makes_no_external_request(): void
    {
        Bus::fake();
        Http::fake();
        $this->artisan('ops:queue-probe', ['--wait' => 0])->assertSuccessful();
        Bus::assertDispatched(RuntimeQueueProbe::class, fn ($job) => $job->connection === 'database' && $job->queue === 'default');
        Http::assertNothingSent();
    }

    public function test_worker_acknowledgement_is_expiring_and_contains_only_a_timestamp(): void
    {
        $this->freezeTime();
        (new RuntimeQueueProbe('fixture'))->handle();
        $this->assertSame(now()->toIso8601String(), Cache::get('ops:queue-probe:fixture'));
        $this->travel(11)->minutes();
        $this->assertNull(Cache::get('ops:queue-probe:fixture'));
    }

    public function test_scheduler_verification_requires_a_recent_heartbeat(): void
    {
        $this->artisan('ops:status', ['--verify' => true])->assertFailed();
        Cache::put('ops:scheduler:last-tick', now()->subMinute()->toIso8601String());
        $this->artisan('ops:status', ['--verify' => true])->assertSuccessful();
        Cache::put('ops:scheduler:last-tick', now()->subMinutes(4)->toIso8601String());
        $this->artisan('ops:status', ['--verify' => true])->assertFailed();
    }
}
