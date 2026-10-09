<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminQueueHealthTest extends TestCase
{
    use RefreshDatabase;

    private function operatorToken(): string
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => 'admin'])->save();
        return $user->createToken('operations')->plainTextToken;
    }

    private function jobs(int $count, int $age): void
    {
        DB::table('jobs')->insert(array_fill(0, $count, [
            'queue' => 'email', 'payload' => '{"private":"must never be returned"}',
            'attempts' => 0, 'reserved_at' => null, 'available_at' => now()->timestamp,
            'created_at' => now()->timestamp - $age,
        ]));
    }

    public function test_only_operations_admin_credentials_can_read_depth(): void
    {
        foreach (['/admin/health/queue-depth', '/api/v1/admin/health/queue-depth'] as $path) {
            $this->getJson($path)->assertUnauthorized();
            $patient = User::factory()->create();
            $this->withToken($patient->createToken('patient')->plainTextToken)->getJson($path)->assertForbidden();
            app('auth')->forgetGuards();
            $admin = User::factory()->create();
            $admin->forceFill(['role' => 'admin'])->save();
            $analytics = $admin->createToken('cozy-admin-dashboard', ['admin:metrics'], now()->addMinutes(30));
            $this->withToken($analytics->plainTextToken)->getJson($path)->assertForbidden();
            app('auth')->forgetGuards();
            $this->withToken($this->operatorToken())->getJson($path)->assertOk()->assertExactJson([
                'depth' => 0, 'oldest_job_age_seconds' => 0, 'warning' => false,
            ])->assertHeader('Cache-Control', 'no-store, private');
            $this->flushHeaders();
            app('auth')->forgetGuards();
        }
    }

    public function test_warning_thresholds_and_oldest_age_include_all_queues_without_payloads(): void
    {
        $this->freezeTime();
        $this->withToken($this->operatorToken());
        $this->jobs(100, 3600);
        $this->getJson('/admin/health/queue-depth')->assertExactJson([
            'depth' => 100, 'oldest_job_age_seconds' => 3600, 'warning' => false,
        ]);
        $this->jobs(1, 10);
        DB::table('jobs')->where('id', DB::table('jobs')->max('id'))->update(['queue' => 'default']);
        $this->getJson('/admin/health/queue-depth')->assertExactJson([
            'depth' => 101, 'oldest_job_age_seconds' => 3600, 'warning' => true,
        ]);
        DB::table('jobs')->delete();
        $this->jobs(1, 3601);
        $this->getJson('/admin/health/queue-depth')->assertExactJson([
            'depth' => 1, 'oldest_job_age_seconds' => 3601, 'warning' => true,
        ]);
        DB::table('jobs')->update(['created_at' => now()->timestamp + 60]);
        $this->getJson('/admin/health/queue-depth')->assertJsonPath('oldest_job_age_seconds', 0);
    }
}
