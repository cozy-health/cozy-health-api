<?php

// Destructive fixtures ONLY in the isolated loopback CI database.
require __DIR__.'/../../vendor/autoload.php';

use App\Jobs\SendCommunityModerationNotice;
use App\Models\User;
use App\Services\CommunityModeration;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Artisan, DB, Http, Mail, Schema};

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (app()->environment() !== 'testing' || config('database.default') !== 'mysql'
    || config('database.connections.mysql.database') !== 'cozy_release_test' || config('database.connections.mysql.username') !== 'root'
    || !in_array(config('database.connections.mysql.host'), ['127.0.0.1', 'localhost'], true) || config('database.connections.mysql.url')) {
    throw new RuntimeException('Community moderation requires isolated loopback CI database.');
}
Http::preventStrayRequests(); Mail::fake();
config(['community.release_ready' => true, 'community.guidelines_version' => 'race-v1', 'plan_access.enabled' => false]);
function verifyModeration(bool $pass, string $message): void { if (!$pass) throw new RuntimeException($message); }
if (($argv[1] ?? '') === 'worker') {
    [, , $race, $worker, $actor, $path, $payload, $capability] = $argv;
    DB::table('moderation_test_barriers')->insert(['race' => $race, 'worker' => $worker]);
    $deadline = microtime(true) + 20;
    while (DB::table('moderation_test_barriers')->where('race', $race)->count() < 2) {
        if (microtime(true) > $deadline) throw new RuntimeException('Moderation barrier timed out.');
        usleep(10000);
    }
    if ($capability === 'mail') {
        (new SendCommunityModerationNotice($path))->handle();
        echo json_encode(['status' => 200]); exit;
    }
    $user = User::findOrFail($actor);
    $token = $user->createToken($capability === 'admin' ? 'cozy-admin-management-v1' : 'device', $capability === 'admin' ? ['admin:moderation'] : ['*'], now()->addMinutes(5))->plainTextToken;
    $request = Request::create('/api/v1/'.$path, 'POST', [], [], [], ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$token], base64_decode($payload, true));
    $kernel = app(\Illuminate\Contracts\Http\Kernel::class);
    $response = $kernel->handle($request); $kernel->terminate($request, $response);
    echo json_encode(['status' => $response->getStatusCode()]); exit;
}
Artisan::call('migrate:fresh', ['--force' => true]);
\App\Models\FeatureFlag::updateOrCreate(['key' => 'community'], ['enabled' => true]);
Schema::create('moderation_test_barriers', function ($t) { $t->string('race'); $t->unsignedInteger('worker'); $t->primary(['race', 'worker']); });
function moderationRace(string $name, array $operations): array
{
    $processes = [];
    foreach ($operations as $worker => [$actor, $path, $payload, $capability]) {
        $process = proc_open([PHP_BINARY, __FILE__, 'worker', $name, (string) $worker, (string) $actor, $path, base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)), $capability], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        verifyModeration(is_resource($process), 'Cannot start moderation race worker.'); fclose($pipes[0]); $processes[] = [$process, $pipes];
    }
    $statuses = [];
    foreach ($processes as [$process, $pipes]) {
        $out = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        verifyModeration(proc_close($process) === 0, 'Moderation worker failed: '.$error);
        $statuses[] = json_decode($out, true, flags: JSON_THROW_ON_ERROR)['status'];
    }
    sort($statuses); return $statuses;
}
$admin = User::factory()->create(['role' => 'admin']); $user = User::factory()->create();
$operation = [$admin->id, 'admin/community-moderation/bans', ['user_id' => $user->id, 'duration' => '7d', 'reason' => 'Synthetic concurrency reason'], 'admin'];
verifyModeration(moderationRace('ban', [$operation, $operation]) === [201, 409], 'Concurrent bans must not both commit.');
$ban = DB::table('community_decisions')->where('kind', 'ban')->first();
$operation = [$user->id, 'community/decisions/'.$ban->id.'/appeal', ['message' => 'Synthetic request to review restriction'], 'patient'];
verifyModeration(moderationRace('appeal', [$operation, $operation]) === [200, 200], 'Appeal retries must be idempotent.');
verifyModeration(DB::table('community_appeals')->count() === 1, 'Duplicate appeals persisted.');
$appeal = DB::table('community_appeals')->first();
$operation = [$admin->id, 'admin/community-moderation/appeals/'.$appeal->id.'/review', ['expected_version' => $appeal->version, 'decision' => 'accepted', 'reason' => 'Synthetic review accepted'], 'admin'];
verifyModeration(moderationRace('review', [$operation, $operation]) === [200, 409], 'Only one appeal review may commit.');
verifyModeration(app(CommunityModeration::class)->active($user->id) === null, 'Accepted appeal did not lift ban.');
$notice = DB::table('community_moderation_notices')->first();
$operation = [$user->id, $notice->id, [], 'mail'];
verifyModeration(moderationRace('mail', [$operation, $operation]) === [200, 200], 'Duplicate delivery workers failed.');
verifyModeration((int) DB::table('community_moderation_notices')->where('id', $notice->id)->value('attempts') === 1, 'Duplicate notice attempted twice.');
$writer = User::factory()->create();
$race = moderationRace('participation', [
    [$admin->id, 'admin/community-moderation/bans', ['user_id' => $writer->id, 'duration' => '24h', 'reason' => 'Synthetic participation race'], 'admin'],
    [$writer->id, 'community/posts', ['body' => 'Synthetic race post', 'guidelines_version' => 'race-v1', 'guidelines_accepted' => true], 'patient'],
]);
verifyModeration(in_array($race, [[200, 201], [201, 403]], true), 'Ban and participation must serialize.');
verifyModeration(app(CommunityModeration::class)->active($writer->id) !== null, 'Participation race lost the ban.');
Schema::drop('moderation_test_barriers');
echo "Community moderation MySQL concurrency passed: bans, appeals, reviews and notice deduplication.\n";
