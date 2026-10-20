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

    public function test_heartbeat_is_expiring_and_repeated_ticks_succeed(): void
    {
        $this->freezeTime();
        $this->artisan('ops:heartbeat')->assertSuccessful();
        $this->artisan('ops:heartbeat')->assertSuccessful();
        $this->assertSame(now()->toIso8601String(), Cache::get('ops:scheduler:last-tick'));
        $this->travel(11)->minutes();
        $this->assertNull(Cache::get('ops:scheduler:last-tick'));
    }

    public function test_heartbeat_accepts_an_unchanged_verified_cache_write(): void
    {
        $this->freezeTime();
        Cache::shouldReceive('put')->once()->andReturn(false);
        Cache::shouldReceive('get')->once()->with('ops:scheduler:last-tick')->andReturn(now()->toIso8601String());
        $this->artisan('ops:heartbeat')->assertSuccessful();
    }

    public function test_heartbeat_fails_if_a_rejected_write_is_not_present(): void
    {
        Cache::shouldReceive('put')->once()->andReturn(false);
        Cache::shouldReceive('get')->once()->with('ops:scheduler:last-tick')->andReturn(null);
        $this->artisan('ops:heartbeat')->expectsOutput('Scheduler heartbeat could not be stored.')->assertFailed();
    }

    public function test_heartbeat_fails_without_disclosing_storage_exception_details(): void
    {
        Cache::shouldReceive('put')->once()->andThrow(new \RuntimeException('private connection details'));
        $this->artisan('ops:heartbeat')->expectsOutput('Scheduler heartbeat cache is unavailable.')->assertFailed();
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
