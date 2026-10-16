<?php

if (getenv('APP_ENV') !== 'testing' || getenv('DB_CONNECTION') !== 'mysql'
    || getenv('DB_DATABASE') !== 'cozy_affirmation_concurrency_test'
    || getenv('DB_USERNAME') !== 'cozy_affirmation_test'
    || ! in_array(getenv('DB_HOST'), ['127.0.0.1', 'localhost'], true)) {
    fwrite(STDERR, "Refusing to run: dedicated local affirmation MySQL fixture required.\n");
    exit(78);
}

require dirname(__DIR__, 2).'/vendor/autoload.php';

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Process\Process;

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || config('database.default') !== 'mysql'
    || config('database.connections.mysql.database') !== 'cozy_affirmation_concurrency_test'
    || config('database.connections.mysql.username') !== 'cozy_affirmation_test'
    || ! in_array(config('database.connections.mysql.host'), ['127.0.0.1', 'localhost'], true)
    || config('database.connections.mysql.url') || config('cache.default') !== 'database') {
    throw new RuntimeException('Resolved fixture configuration failed its isolation guard.');
}

Carbon::setTestNow('2026-10-16 14:30:00 UTC');
if (($argv[1] ?? null) === 'worker') {
    exit(Artisan::call('ops:heartbeat'));
}

$workers = [];
try {
    Cache::forget('ops:scheduler:last-tick');
    for ($i = 0; $i < 2; $i++) {
        if (Artisan::call('ops:heartbeat') !== 0) {
            throw new RuntimeException('Repeated same-second heartbeat failed.');
        }
    }
    for ($i = 0; $i < 8; $i++) {
        $worker = new Process([PHP_BINARY, __FILE__, 'worker'], dirname(__DIR__, 2));
        $worker->setTimeout(40);
        $worker->start();
        $workers[] = $worker;
    }
    foreach ($workers as $worker) {
        $worker->wait();
        if (! $worker->isSuccessful()) {
            throw new RuntimeException('Concurrent heartbeat failed: '.$worker->getErrorOutput());
        }
    }
    if (Cache::get('ops:scheduler:last-tick') !== now()->toIso8601String()) {
        throw new RuntimeException('Stored heartbeat does not match the shared clock.');
    }
    Carbon::setTestNow('2026-10-16 14:41:00 UTC');
    if (Cache::get('ops:scheduler:last-tick') !== null) {
        throw new RuntimeException('Heartbeat did not expire.');
    }
    echo "Verified repeated and eight concurrent same-second MySQL heartbeats and expiry.\n";
} finally {
    foreach ($workers as $worker) {
        if ($worker->isRunning()) {
            $worker->stop();
        }
    }
    Cache::forget('ops:scheduler:last-tick');
}
