<?php

// Disposable CI database only. Never point this destructive fixture at development data.
require __DIR__.'/../../vendor/autoload.php';
use App\Models\PrivacyRequest;
use App\Models\SupportReport;
use App\Models\User;
use App\Services\CustomerPrivacy;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (app()->environment() !== 'testing' || config('database.default') !== 'mysql'
    || config('database.connections.mysql.database') !== 'cozy_release_test'
    || config('database.connections.mysql.username') !== 'root'
    || ! in_array(config('database.connections.mysql.host'), ['127.0.0.1', 'localhost'], true)
    || config('database.connections.mysql.url')) {
    throw new RuntimeException('Customer-operations CI database isolation check failed.');
}
Http::preventStrayRequests();
Mail::fake();
function verifyOperations(bool $value, string $message): void
{
    if (! $value) {
        throw new RuntimeException($message);
    }
}
if (($argv[1] ?? '') === 'worker') {
    [, , $race, $worker, $actorId, $path, $payload, $capability] = $argv;
    DB::table('operations_test_barriers')->insert(['race' => $race, 'worker' => $worker]);
    $deadline = microtime(true) + 15;
    while (DB::table('operations_test_barriers')->where('race', $race)->count() < 2) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Operations race barrier timed out.');
        }
        usleep(10000);
    }
    $actor = User::findOrFail($actorId);
    config(['operations.support_reviewers' => [(int) $actorId], 'operations.privacy_reviewers' => [(int) $actorId]]);
    $token = $actor->createToken($capability === 'customer' ? 'operations-fixture' : 'cozy-admin-management-v1',
        $capability === 'customer' ? ['*'] : ['admin:'.$capability], now()->addMinutes(5))->plainTextToken;
    $request = Request::create('/api/v1/'.$path, 'POST', [], [], [],
        ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$token], base64_decode($payload, true));
    $kernel = app(Illuminate\Contracts\Http\Kernel::class);
    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);
    echo json_encode(['status' => $response->getStatusCode()]);
    exit(0);
}
Artisan::call('migrate:fresh', ['--force' => true]);
Schema::create('operations_test_barriers', function ($table) {
    $table->string('race');
    $table->unsignedInteger('worker');
    $table->primary(['race', 'worker']);
});
function raceOperations(string $race, User $actor, string $path, array $payloads, string $capability): array
{
    $processes = [];
    foreach ($payloads as $worker => $payload) {
        $process = proc_open([PHP_BINARY, __FILE__, 'worker', $race, (string) $worker, (string) $actor->id, $path,
            base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)), $capability], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (! is_resource($process)) {
            throw new RuntimeException('Could not start operations race worker.');
        }
        fclose($pipes[0]);
        $processes[] = [$process, $pipes];
    }
    $statuses = [];
    foreach ($processes as [$process, $pipes]) {
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        verifyOperations(proc_close($process) === 0, 'Operations race worker failed: '.$error);
        $statuses[] = json_decode($output, true, flags: JSON_THROW_ON_ERROR)['status'];
    }
    sort($statuses);

    return $statuses;
}
$customer = User::factory()->create();
$report = SupportReport::create(['user_id' => $customer->id, 'subject' => 'Synthetic race', 'message' => 'Fixture only']);
$payload = ['body' => 'One reply', 'request_id' => (string) Str::uuid(), 'expected_version' => 0];
verifyOperations(raceOperations('duplicate', $customer, 'support/reports/'.$report->id.'/replies', [$payload, $payload], 'customer') === [200, 200], 'Identical concurrent reply retries must succeed idempotently.');
verifyOperations(DB::table('support_messages')->where('support_report_id', $report->id)->count() === 1, 'Duplicate reply race persisted more than once.');
$report = SupportReport::create(['user_id' => $customer->id, 'subject' => 'Conflict race', 'message' => 'Fixture only']);
verifyOperations(raceOperations('conflict', $customer, 'support/reports/'.$report->id.'/replies', [$payload, [...$payload, 'request_id' => (string) Str::uuid(), 'body' => 'Other reply']], 'customer') === [200, 409], 'Stale concurrent replies must not both commit.');
$admin = User::factory()->create(['role' => 'admin']);
$request = PrivacyRequest::create(['id' => (string) Str::uuid(), 'user_id' => $customer->id, 'request_id' => (string) Str::uuid(),
    'kind' => 'export', 'status' => 'pending', 'changes' => [], 'verified_at' => now(), 'identity_fingerprint' => app(CustomerPrivacy::class)->fingerprint($customer)]);
$decision = ['decision' => 'approve', 'expected_version' => 1, 'outcome' => 'Synthetic verified request.'];
verifyOperations(raceOperations('decision', $admin, 'admin/privacy-requests/'.$request->id.'/process', [$decision, $decision], 'privacy') === [200, 409], 'Only one privacy reviewer may commit a decision.');
verifyOperations(DB::table('admin_audit_logs')->where('resource_id', $request->id)->where('action', 'approve')->count() === 1, 'Concurrent privacy review duplicated its audit decision.');
Schema::drop('operations_test_barriers');
echo "Customer operations MySQL concurrency passed: duplicate reply, stale reply and privacy decision.\n";
