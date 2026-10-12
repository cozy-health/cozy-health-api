<?php

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\{Cache, DB, Schema};

class RuntimeStatus extends Command
{
    protected $signature = 'ops:status {--verify : Fail if the scheduler heartbeat is missing or stale}';
    protected $description = 'Report non-sensitive scheduler, queue and push readiness';

    public function handle(): int
    {
        $heartbeat = Cache::get('ops:scheduler:last-tick');
        $fresh = false;
        try {
            $fresh = $heartbeat && CarbonImmutable::parse($heartbeat)->between(now()->subMinutes(3), now());
        } catch (\Throwable) {}
        $this->table(['Check', 'Value'], [
            ['Database driver', DB::connection()->getDriverName()],
            ['Queue connection', config('queue.default')],
            ['Shared cache', config('cache.default')],
            ['Scheduler last tick', $heartbeat ?? 'not observed'],
            ['Scheduler fresh', $fresh ? 'yes' : 'no'],
            ['Push enabled', config('push.enabled') ? 'yes' : 'no'],
            ['iOS push enabled', config('push.ios_enabled') ? 'yes' : 'no'],
            ['Firebase project configured', config('push.project_id') ? 'yes' : 'no'],
            ['Firebase credentials configured', (config('push.credentials_json') || config('push.credentials')) ? 'yes' : 'no'],
        ]);
        foreach (['jobs', 'failed_jobs', 'push_reminders', 'push_attempts'] as $table) {
            $this->line($table.': '.(Schema::hasTable($table) ? DB::table($table)->count().' rows' : 'missing'));
        }
        return $this->option('verify') && ! $fresh ? self::FAILURE : self::SUCCESS;
    }
}
