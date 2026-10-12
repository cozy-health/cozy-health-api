<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/** A runtime acknowledgement with no user data, messages or external effects. */
class RuntimeQueueProbe implements ShouldQueue
{
    use Queueable;

    public $tries = 1;
    public $timeout = 10;

    public function __construct(public string $probeId) {}

    public function handle(): void
    {
        Cache::put('ops:queue-probe:'.$this->probeId, now()->toIso8601String(), now()->addMinutes(10));
    }
}
