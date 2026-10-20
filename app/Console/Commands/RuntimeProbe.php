<?php

namespace App\Console\Commands;

use App\Jobs\RuntimeQueueProbe;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class RuntimeProbe extends Command
{
    protected $signature = 'ops:queue-probe {--wait=20 : Seconds to wait for the database worker (0-30)}';
    protected $description = 'Verify a real queue worker without sending messages or using account data';

    public function handle(): int
    {
        $wait = filter_var($this->option('wait'), FILTER_VALIDATE_INT);
        if ($wait === false || $wait < 0 || $wait > 30) {
            $this->error('Wait must be an integer from 0 to 30.');
            return self::FAILURE;
        }
        $id = (string) Str::uuid();
        RuntimeQueueProbe::dispatch($id)->onConnection('database')->onQueue('default');
        $this->line('Probe: '.$id);
        if ($wait === 0) {
            $this->info('Queued on database/default; no notification or email will be sent.');
            return self::SUCCESS;
        }
        $deadline = microtime(true) + $wait;
        do {
            if ($ack = Cache::get('ops:queue-probe:'.$id)) {
                $this->info('Worker acknowledged at '.$ack);
                return self::SUCCESS;
            }
            usleep(250000);
        } while (microtime(true) < $deadline);
        $this->error('No acknowledgement before timeout; queue verification is pending.');
        return self::FAILURE;
    }
}
