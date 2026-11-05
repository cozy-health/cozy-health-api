<?php

// Destructive fixtures are restricted to a disposable loopback CI database.
require __DIR__.'/../../vendor/autoload.php';

use App\Models\Provider;
use App\Models\ProviderClientLink;
use App\Models\ProviderMessage;
use App\Models\User;
use App\Services\Provider\ProviderReplacementService;
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
    throw new RuntimeException('Provider replacement CI database isolation check failed.');
}
Http::preventStrayRequests();
Mail::fake();

function verifyReplacement(bool $ok, string $message): void
{
    if (! $ok) {
        throw new RuntimeException($message);
    }
}

if (($argv[1] ?? '') === 'worker') {
    [, , $race, $worker, $actorType, $actorId, $method, $path, $payload] = $argv;
    DB::table('replacement_test_barriers')->insert(['race' => $race, 'worker' => $worker]);
    $deadline = microtime(true) + 15;
    while (DB::table('replacement_test_barriers')->where('race', $race)->count() < 2) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Replacement barrier timed out.');
        }
        usleep(10000);
    }
    $actor = $actorType === 'provider' ? Provider::findOrFail($actorId) : User::findOrFail($actorId);
    $token = $actor->createToken('replacement-fixture', ['*'], now()->addMinutes(5))->plainTextToken;
    $request = Request::create('/api/v1/'.$path, $method, [], [], [],
        ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$token], base64_decode($payload, true));
    $kernel = app(Illuminate\Contracts\Http\Kernel::class);
    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);
    echo json_encode(['status' => $response->getStatusCode()]);
    exit(0);
}

Artisan::call('migrate:fresh', ['--force' => true]);
Schema::create('replacement_test_barriers', function ($table) {
    $table->string('race');
    $table->unsignedInteger('worker');
    $table->primary(['race', 'worker']);
});

function raceReplacement(string $name, array $calls): array
{
    $processes = [];
    foreach ($calls as $worker => [$type, $id, $method, $path, $body]) {
        $process = proc_open([PHP_BINARY, __FILE__, 'worker', $name, (string) $worker, $type, (string) $id,
            $method, $path, base64_encode(json_encode($body, JSON_THROW_ON_ERROR))],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (! is_resource($process)) {
            throw new RuntimeException('Cannot start replacement worker.');
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
        verifyReplacement(proc_close($process) === 0, 'Replacement worker failed: '.$error);
        $statuses[] = json_decode($output, true, flags: JSON_THROW_ON_ERROR)['status'];
    }

    return $statuses;
}

function replacementFixture(string $tag): array
{
    $patient = User::factory()->create();
    $old = Provider::create(['name' => 'Original fixture', 'email' => 'old-'.$tag.'@example.test', 'is_active' => true]);
    $next = Provider::create(['name' => 'Replacement fixture', 'email' => 'next-'.$tag.'@example.test', 'is_active' => true]);
    $link = ProviderClientLink::create(['user_id' => $patient->id, 'provider_id' => $old->id, 'provider_email' => $old->email, 'verified_at' => now()->subMonth()]);
    ProviderMessage::create(['provider_client_link_id' => $link->id, 'client_id' => (string) Str::uuid(), 'sender' => 'patient',
        'content' => 'Synthetic fixture', 'created_at' => now()->subDays(8)]);

    return [$patient, $next, $link];
}

[$patient, $next, $original] = replacementFixture('start');
$body = ['original_link_id' => $original->id, 'provider_email' => $next->email, 'request_id' => (string) Str::uuid()];
$statuses = raceReplacement('same-request', [
    ['patient', $patient->id, 'POST', 'provider/replacements', $body],
    ['patient', $patient->id, 'POST', 'provider/replacements', $body],
]);
sort($statuses);
verifyReplacement($statuses === [200, 201], 'Same replacement request must commit once and retry successfully.');
verifyReplacement(DB::table('provider_replacement_requests')->where('user_id', $patient->id)->count() === 1, 'Duplicate replacement receipt.');

foreach (['cancel', 'revoke'] as $case) {
    [$patient, $next, $original] = replacementFixture($case);
    $request = Request::create('/');
    $request->setUserResolver(fn () => $patient);
    [$pending] = app(ProviderReplacementService::class)->start($request,
        ['original_link_id' => $original->id, 'provider_email' => $next->email, 'request_id' => (string) Str::uuid()]);
    $accept = ['code' => $pending->invitation_code_secret, 'invitation_id' => $pending->id,
        'care_acknowledged' => true, 'revocation_acknowledged' => true,
        'consent_flags' => array_fill_keys(['moods', 'journals', 'quizzes', 'notes'], false)];
    $path = $case === 'cancel' ? 'provider/replacements/'.$pending->replacement_request_id : 'me/provider/'.$original->id;
    $statuses = raceReplacement('accept-'.$case, [
        ['provider', $next->id, 'POST', 'provider/invitations/connect', $accept],
        ['patient', $patient->id, 'DELETE', $path, []],
    ]);
    foreach ($statuses as $status) {
        verifyReplacement(in_array($status, [200, 409, 422, 404], true), 'Unexpected race failure: '.$status);
    }
    $pending->refresh();
    $original->refresh();
    if ($pending->verified_at) {
        verifyReplacement($pending->replacement_status === 'accepted' && $original->revoked_at !== null, 'Replacement accepted without original revocation.');
        verifyReplacement(! in_array(true, $pending->consentFlags(), true), 'Replacement inherited sharing.');
    } else {
        verifyReplacement($pending->revoked_at !== null && $pending->replacement_status === 'cancelled', 'Cancelled replacement remains redeemable.');
    }
}
echo "Provider replacement MySQL races passed: duplicate start, accept/cancel, accept/original revoke.\n";
