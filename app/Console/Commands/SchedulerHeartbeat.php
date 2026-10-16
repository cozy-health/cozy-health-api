<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

class SchedulerHeartbeat extends Command
{
    protected $signature = 'ops:heartbeat';

    protected $description = 'Store the scheduler heartbeat in the shared cache';

    public function handle(): int
    {
        $heartbeat = now()->toIso8601String();
        try {
            $written = Cache::put('ops:scheduler:last-tick', $heartbeat, now()->addMinutes(10));
            // MySQL reports zero changed rows when another process stored the same value.
            if (! $written && Cache::get('ops:scheduler:last-tick') !== $heartbeat) {
                $this->error('Scheduler heartbeat could not be stored.');

                return self::FAILURE;
            }
        } catch (Throwable) {
            $this->error('Scheduler heartbeat cache is unavailable.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
