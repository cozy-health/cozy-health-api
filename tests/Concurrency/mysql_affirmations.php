<?php

// Only a disposable local database is allowed: the parent recreates its schema.
if (getenv('APP_ENV') !== 'testing' || getenv('DB_CONNECTION') !== 'mysql'
    || getenv('DB_DATABASE') !== 'cozy_affirmation_concurrency_test'
    || ! in_array(getenv('DB_HOST'), ['127.0.0.1', 'localhost'], true)
    || getenv('DB_USERNAME') !== 'cozy_affirmation_test') {
    fwrite(STDERR, "Refusing to run: dedicated local affirmation MySQL test environment required.\n");
    exit(78);
}

require dirname(__DIR__, 2).'/vendor/autoload.php';

use App\Models\Affirmation;
use App\Models\User;
use App\Services\AffirmationAssignmentService;
use Database\Seeders\AffirmationSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Process\Process;

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || config('database.default') !== 'mysql'
    || config('database.connections.mysql.database') !== 'cozy_affirmation_concurrency_test'
    || config('database.connections.mysql.username') !== 'cozy_affirmation_test'
    || ! in_array(config('database.connections.mysql.host'), ['127.0.0.1', 'localhost'], true)
    || config('database.connections.mysql.url')) {
    throw new RuntimeException('Resolved database failed the isolation guard.');
}
Http::preventStrayRequests();
Mail::fake();

if (($argv[1] ?? null) === 'worker') {
    touch($argv[4].'.ready.'.getmypid());
    $deadline = microtime(true) + 20;
    while (! is_file($argv[4])) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Start barrier timed out.');
        }
        usleep(10000);
    }
    Carbon::setTestNow(Carbon::parse($argv[3], 'UTC'));
    echo json_encode(app(AffirmationAssignmentService::class)->current(User::findOrFail($argv[2])), JSON_THROW_ON_ERROR);
    exit;
}

Artisan::call('migrate:fresh', ['--force' => true]);
Artisan::call('db:seed', ['--class' => AffirmationSeeder::class, '--force' => true]);
$user = User::factory()->create(['email' => 'assignment@concurrency.invalid']);
$results = [];
foreach (['2026-10-16T14:30:00Z', '2026-10-17T14:30:00Z'] as $at) {
    $gate = sys_get_temp_dir().'/affirmation-gate-'.bin2hex(random_bytes(8));
    $workers = [];
    try {
        DB::beginTransaction();
        DB::table('users')->where('id', $user->id)->lockForUpdate()->first();
        for ($i = 0; $i < 8; $i++) {
            $worker = new Process([PHP_BINARY, __FILE__, 'worker', (string) $user->id, $at, $gate], dirname(__DIR__, 2));
            $worker->setTimeout(40);
            $worker->start();
            $workers[] = $worker;
        }
        $deadline = microtime(true) + 25;
        while (count(glob($gate.'.ready.*')) !== 8) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Workers did not reach the barrier.');
            }
            usleep(10000);
        }
        touch($gate);
        // Give all eight requests time to queue behind the same existing user row.
        usleep(200000);
        DB::commit();
        $assignments = [];
        foreach ($workers as $worker) {
            $worker->wait();
            if (! $worker->isSuccessful()) {
                throw new RuntimeException($worker->getErrorOutput());
            }
            $assignments[] = json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        }
        if (count(array_unique(array_column($assignments, 'id'))) !== 1
            || count(array_unique(array_column($assignments, 'assigned_at'))) !== 1
            || count(array_unique(array_column($assignments, 'expires_at'))) !== 1
            || DB::table('user_affirmation_assignments')->where('user_id', $user->id)->count() !== 1) {
            throw new RuntimeException('Concurrent devices disagreed on the assignment.');
        }
        $results[] = $assignments[0];
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        foreach ($workers as $worker) {
            if ($worker->isRunning()) {
                $worker->stop();
            }
        }
        if (is_file($gate)) {
            unlink($gate);
        }
        foreach (glob($gate.'.ready.*') as $ready) {
            unlink($ready);
        }
    }
}
$expected = Affirmation::where('is_active', true)->where('id', '>', $results[0]['id'])->orderBy('id')->value('id')
    ?? Affirmation::where('is_active', true)->min('id');
if ($results[1]['id'] !== $expected || $results[1]['expires_at'] !== '2026-10-18T14:30:00+00:00') {
    throw new RuntimeException('Concurrent expiry advanced more than one step or changed the window.');
}
echo "Verified eight simultaneous devices on first assignment and exact expiry.\n";
