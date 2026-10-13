<?php

// This executable is intentionally unusable against an ordinary app database.
if (getenv('APP_ENV') !== 'testing' || getenv('DB_CONNECTION') !== 'mysql'
    || getenv('DB_DATABASE') !== 'cozy_push_concurrency_test'
    || ! in_array(getenv('DB_HOST'), ['127.0.0.1', 'localhost'], true)
    || getenv('DB_USERNAME') !== 'cozy_push_test') {
    fwrite(STDERR, "Refusing to run: dedicated local MySQL test environment required.\n");
    exit(78);
}

require dirname(__DIR__, 2).'/vendor/autoload.php';

use App\Jobs\SendPushAttempt;
use App\Models\{AppNotification, FeatureFlag, MoodEntry, PushDevice, PushReminder, User, UserProfile};
use App\Services\Push\{PushSender, ReminderScheduler};
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\{Artisan, DB, Http, Mail, Queue, Schema};
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (! $app->environment('testing') || config('database.default') !== 'mysql'
    || config('database.connections.mysql.database') !== 'cozy_push_concurrency_test'
    || ! in_array(config('database.connections.mysql.host'), ['127.0.0.1', 'localhost'], true)
    || config('database.connections.mysql.username') !== 'cozy_push_test'
    || config('database.connections.mysql.url')) {
    throw new RuntimeException('Resolved database configuration did not pass the isolation guard.');
}
config(['push.enabled' => true, 'push.ios_enabled' => true, 'push.project_id' => null,
    'push.credentials' => null, 'push.credentials_json' => null,
    'queue.default' => 'database', 'cache.default' => 'database']);
Http::preventStrayRequests();
Mail::fake();

function clockAt(string $time): void
{
    Carbon::setTestNow(CarbonImmutable::parse($time));
    CarbonImmutable::setTestNow(CarbonImmutable::parse($time));
}

class RecordingSender implements PushSender
{
    public function __construct(private string $result) {}
    public function send(PushDevice $device, AppNotification $notification): string
    {
        DB::table('push_test_calls')->insert(['user_id' => $notification->user_id,
            'reminder_id' => $notification->id, 'device_id' => $device->id,
            'result' => $this->result, 'sent_at' => now()->utc()->toDateTimeString()]);
        usleep(50000);
        return $this->result;
    }
}

function verify(bool $condition, string $message): void
{
    if (! $condition) throw new RuntimeException($message);
}

function fresh(): void
{
    Artisan::call('migrate:fresh', ['--force' => true]);
    Schema::create('push_test_calls', function (Blueprint $table) {
        $table->id(); $table->unsignedBigInteger('user_id'); $table->uuid('reminder_id');
        $table->unsignedBigInteger('device_id'); $table->string('result'); $table->dateTime('sent_at');
    });
    Schema::create('push_test_runs', function (Blueprint $table) {
        $table->id(); $table->uuid('round'); $table->integer('worker');
        $table->double('started'); $table->double('finished')->nullable();
    });
    FeatureFlag::updateOrCreate(['key' => 'push_notifications'], ['enabled' => true]);
}

function account(int $devices = 3, array $settings = []): User
{
    $user = User::factory()->create(['name' => 'Concurrency Fixture',
        'email' => Str::uuid().'@push-concurrency.invalid', 'timezone' => 'Africa/Lagos', 'is_active' => true]);
    $resolved = ['quiet_enabled' => false, 'journal_days' => [1,2,3,4,5,6,7], 'journal_time' => '09:00',
        'streak_enabled' => true, 'streak_time' => '09:00', 'affirmation_enabled' => true,
        'affirmation_time' => '09:00'];
    UserProfile::create(['id' => (string) Str::uuid(), 'user_id' => $user->id,
        'name' => $user->name, 'email' => $user->email, 'push_consent' => true,
        'notifications_master' => true, 'daily_checkin_enabled' => true, 'daily_checkin_time' => '09:00',
        'journal_reminder_enabled' => true, 'reminder_settings' => array_replace($resolved, $settings)]);
    MoodEntry::create(['id' => (string) Str::uuid(), 'user_id' => $user->id, 'mood' => 'happy',
        'intensity' => 3, 'client_created_at' => now()->subDay()]);
    for ($i = 0; $i < $devices; $i++) {
        $token = 'fake-only-'.Str::uuid();
        PushDevice::create(['user_id' => $user->id, 'device_id' => 'fixture-'.$i,
            'platform' => $i === 2 ? 'ios' : 'android', 'provider' => 'fcm',
            'token' => $token, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDays(90)]);
    }
    return $user;
}

function onlyMood(User $user, string $time = '09:00'): void
{
    $profile = $user->profile()->first();
    $profile->update(['daily_checkin_time' => $time, 'journal_reminder_enabled' => false,
        'reminder_settings' => array_replace($profile->reminder_settings,
            ['streak_enabled' => false, 'affirmation_enabled' => false])]);
}

function slots(User $user): int
{
    return PushReminder::where('user_id', $user->id)->whereNotNull('dispatched_at')->count();
}

function duplicateJobs(bool $includeTerminal = false): void
{
    $attempts = DB::table('push_attempts');
    if (! $includeTerminal) $attempts->whereIn('status', ['queued', 'transient']);
    foreach ($attempts->get() as $attempt) {
        for ($i = 0; $i < 16; $i++) Queue::connection('database')->push(new SendPushAttempt($attempt->id));
    }
}

if (($argv[1] ?? '') === 'worker') {
    [$action, $userId, $time, $directory, $round, $worker, $result] = array_slice($argv, 2);
    clockAt($time);
    $app->instance(PushSender::class, new RecordingSender($result));
    file_put_contents($directory.'/ready-'.$worker, 'ready');
    $deadline = microtime(true) + 30;
    while (! is_file($directory.'/start')) {
        if (microtime(true) > $deadline) throw new RuntimeException('Worker barrier timed out.');
        usleep(10000);
    }
    DB::table('push_test_runs')->insert(['round' => $round, 'worker' => $worker, 'started' => microtime(true)]);
    if ($action === 'schedule') app(ReminderScheduler::class)->schedule(User::findOrFail($userId));
    elseif ($action === 'tick') app(ReminderScheduler::class)->tick();
    elseif ($action === 'queue') {
        Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'default',
            '--stop-when-empty' => true, '--sleep' => 0, '--tries' => 1, '--timeout' => 20]);
    } else throw new RuntimeException('Unsupported worker action.');
    DB::table('push_test_runs')->where('round', $round)->where('worker', $worker)->update(['finished' => microtime(true)]);
    exit(0);
}

$groups = [];
config(['database.connections.observer' => config('database.connections.mysql')]);

function parallel(string $action, User $user, string $result = 'accepted', bool $requireBlocked = true): void
{
    global $groups;
    $round = (string) Str::uuid();
    $directory = dirname(__DIR__, 2).'/storage/framework/testing/push-concurrency-'.$round;
    mkdir($directory, 0700, true);
    $processes = [];
    try {
        for ($i = 0; $i < 16; $i++) {
            $process = new Process([PHP_BINARY, __FILE__, 'worker', $action, (string) $user->id,
                now()->utc()->toIso8601String(), $directory, $round, (string) $i, $result]);
            $process->setTimeout(90);
            $process->start();
            $processes[] = $process;
        }
        $deadline = microtime(true) + 30;
        while (count(glob($directory.'/ready-*')) !== 16) {
            verify(microtime(true) < $deadline, 'Not all 16 processes reached the start barrier.');
            foreach ($processes as $process) {
                verify($process->isRunning(), 'Worker stopped before readiness: '.$process->getErrorOutput());
            }
            usleep(20000);
        }
        // Hold the real user row while every independent process enters its work.
        DB::beginTransaction();
        User::whereKey($user->id)->lockForUpdate()->firstOrFail();
        touch($directory.'/start');
        $deadline = microtime(true) + 15;
        while (DB::connection('observer')->table('push_test_runs')->where('round', $round)->count() !== 16) {
            verify(microtime(true) < $deadline, 'Workers did not overlap at the locked-row barrier.');
            usleep(20000);
        }
        $overlap = DB::connection('observer')->table('push_test_runs')->where('round', $round)->whereNull('finished')->count();
        // Scheduler ticks can finish their retry enqueueing quickly; other groups must wait for the row.
        if ($requireBlocked && $action !== 'tick') verify($overlap === 16, 'Expected 16 concurrent workers blocked at the real user-row lock.');
        DB::commit();
        foreach ($processes as $process) {
            $process->wait();
            verify($process->isSuccessful(), 'Worker failed: '.$process->getErrorOutput().$process->getOutput());
        }
        verify(DB::table('failed_jobs')->count() === 0, 'A real database queue job failed.');
        verify(DB::table('push_test_runs')->where('round', $round)->whereNotNull('finished')->count() === 16, 'Not all workers completed.');
        $groups[] = ['action' => $action, 'workers' => 16, 'overlap' => $overlap];
    } finally {
        if (DB::transactionLevel()) DB::rollBack();
        foreach ($processes as $process) if ($process->isRunning()) $process->stop(1);
        foreach (glob($directory.'/*') as $file) unlink($file);
        rmdir($directory);
    }
}

function uniqueCalls(int $expected): void
{
    $calls = DB::table('push_test_calls')->get();
    verify($calls->count() === $expected, "Expected {$expected} fake delivery calls, got ".$calls->count());
    verify($calls->map(fn ($call) => $call->reminder_id.':'.$call->device_id)->unique()->count() === $expected,
        'Duplicate provider invocation for the same logical reminder/device.');
}

$results = [];
$cases = [
    'daily_cap_spacing_and_three_devices' => function () {
        clockAt('2026-10-13T08:00:00Z'); $user = account();
        parallel('schedule', $user); verify(slots($user) === 1, 'Concurrent schedulers dispatched more than one at 09:00.');
        verify(DB::table('push_attempts')->count() === 3, 'Devices did not share one logical slot.');
        duplicateJobs(); parallel('queue', $user); uniqueCalls(3);
        clockAt('2026-10-13T09:59:00Z'); parallel('schedule', $user);
        verify(slots($user) === 1, 'Two-hour spacing allowed a dispatch after 119 minutes.');
        clockAt('2026-10-13T10:00:00Z'); parallel('schedule', $user);
        verify(slots($user) === 2, 'Second dispatch was not admitted at the two-hour boundary.');
        duplicateJobs(); parallel('queue', $user); uniqueCalls(6);
        clockAt('2026-10-13T12:00:00Z'); parallel('schedule', $user);
        verify(slots($user) === 3, 'Third slot was not reserved.');
        duplicateJobs(); parallel('queue', $user); uniqueCalls(9);
        clockAt('2026-10-13T14:00:00Z'); parallel('schedule', $user);
        verify(slots($user) === 3 && AppNotification::withoutGlobalScope('user')->count() === 3, 'Daily cap exceeded.');
        verify(PushReminder::where('category', 'affirmation')->value('status') === 'expired', 'Fourth category did not expire.');
        verify(PushReminder::count() === 4, 'Category/day uniqueness failed.');
    },
    'elapsed_spacing_across_local_midnight' => function () {
        clockAt('2026-10-13T22:30:00Z'); $user = account(1); onlyMood($user, '23:30');
        parallel('schedule', $user); duplicateJobs(); parallel('queue', $user);
        clockAt('2026-10-13T23:00:00Z'); onlyMood($user, '00:00'); parallel('schedule', $user);
        verify(slots($user) === 1, 'Midnight reset bypassed elapsed spacing.');
        clockAt('2026-10-14T00:29:00Z'); parallel('schedule', $user);
        verify(slots($user) === 1, 'Across-midnight spacing admitted 119 minutes.');
        clockAt('2026-10-14T00:30:00Z'); parallel('schedule', $user);
        duplicateJobs(); parallel('queue', $user); uniqueCalls(2);
        verify(slots($user) === 2, 'Next local day reminder was not admitted after two elapsed hours.');
        $times = DB::table('push_test_calls')->orderBy('id')->pluck('sent_at');
        verify(CarbonImmutable::parse($times[0])->diffInSeconds(CarbonImmutable::parse($times[1])) === 7200.0, 'Actual fake deliveries were not two hours apart.');
    },
    'quiet_hours_rollover_and_streak_expiry' => function () {
        clockAt('2026-10-13T22:00:00Z'); $user = account(1, ['quiet_enabled' => true,
            'journal_time' => '23:00', 'streak_time' => '23:00', 'affirmation_time' => '23:00']);
        $user->profile()->first()->update(['daily_checkin_time' => '23:00']);
        parallel('schedule', $user); verify(slots($user) === 0, 'A reminder dispatched inside overnight quiet hours.');
        clockAt('2026-10-14T07:00:00Z'); parallel('schedule', $user);
        duplicateJobs(); parallel('queue', $user); uniqueCalls(1);
        verify(slots($user) === 1, 'Quiet-hours end delivered a burst or lost the rolled-over mood.');
        foreach (['streak', 'journal', 'affirmation'] as $category) {
            verify(PushReminder::where('category', $category)->where('local_date', '2026-10-13')->value('status') === 'expired', 'Original-day '.$category.' did not expire.');
        }
        parallel('schedule', $user); verify(slots($user) === 1, 'Rolled-over mood was dispatched twice.');
    },
    'ambiguous_outcome_is_never_replayed' => function () {
        clockAt('2026-10-13T08:00:00Z'); $user = account(1); onlyMood($user);
        parallel('schedule', $user); duplicateJobs(); parallel('queue', $user, 'unknown');
        uniqueCalls(1); verify(DB::table('push_attempts')->value('attempts') === 1, 'Duplicate jobs repeated uncertain delivery.');
        duplicateJobs(true); parallel('queue', $user, 'accepted', false); uniqueCalls(1);
        parallel('schedule', $user); verify(slots($user) === 1, 'Uncertain outcome reserved another daily slot.');
    },
    'transient_retry_is_bounded_and_deduplicated' => function () {
        clockAt('2026-10-13T08:00:00Z'); $user = account(1); onlyMood($user);
        parallel('schedule', $user); duplicateJobs(); parallel('queue', $user, 'transient');
        foreach (['2026-10-13T08:02:00Z', '2026-10-13T08:06:00Z'] as $time) {
            clockAt($time); parallel('tick', $user); parallel('queue', $user, 'transient');
        }
        verify(DB::table('push_test_calls')->count() === 3, 'Transient retries duplicated provider invocations.');
        verify(DB::table('push_attempts')->value('attempts') === 3, 'Retry attempt count was not bounded at three.');
        verify(DB::table('push_attempts')->value('status') === 'failed', 'Exhausted transient retry did not fail.');
        clockAt('2026-10-13T08:14:00Z'); parallel('tick', $user);
        verify(DB::table('jobs')->count() === 0 && slots($user) === 1, 'Exhausted retry requeued work or consumed another allowance.');
    },
    'completion_before_delivery_cancels_queued_work' => function () {
        clockAt('2026-10-13T08:00:00Z'); $user = account(); onlyMood($user);
        parallel('schedule', $user);
        MoodEntry::create(['id' => (string) Str::uuid(), 'user_id' => $user->id, 'mood' => 'happy', 'intensity' => 3, 'client_created_at' => now()]);
        duplicateJobs(); parallel('queue', $user); uniqueCalls(0);
        verify(DB::table('push_attempts')->where('status', 'cancelled')->count() === 3, 'Completed activity was not rechecked per device.');
    },
    'late_retry_racing_a_new_category_preserves_spacing' => function () {
        clockAt('2026-10-13T08:00:00Z'); $user = account(1); onlyMood($user);
        parallel('schedule', $user); duplicateJobs(); parallel('queue', $user, 'transient');
        clockAt('2026-10-13T10:00:00Z');
        $profile = $user->profile()->first();
        $profile->update(['journal_reminder_enabled' => true,
            'reminder_settings' => array_replace($profile->reminder_settings, ['journal_time' => '11:00'])]);
        parallel('schedule', $user); verify(slots($user) === 2, 'New category was not reserved at the boundary.');
        duplicateJobs(); parallel('queue', $user, 'accepted');
        $accepted = DB::table('push_test_calls')->where('result', 'accepted')->get();
        verify($accepted->pluck('reminder_id')->unique()->count() === 1, 'Late retry and another category both delivered together, breaking two-hour spacing.');
    },
];

try {
    $rounds = (int) (getenv('PUSH_TEST_ROUNDS') ?: 3);
    verify($rounds >= 1 && $rounds <= 5, 'Verification rounds must be 1-5.');
    $engine = DB::selectOne('select version() as version, @@transaction_isolation as isolation');
    echo "MySQL {$engine->version}; isolation {$engine->isolation}; 16 independent PHP workers per group\n";
    for ($iteration = 1; $iteration <= $rounds; $iteration++) {
        foreach ($cases as $name => $case) {
            fresh(); $case();
            $results[] = ['round' => $iteration, 'case' => $name, 'passed' => true];
            echo "PASS round {$iteration}: {$name}\n";
        }
    }
    $report = ['passed' => true, 'mysql' => $engine->version, 'isolation' => $engine->isolation,
        'cases' => $results, 'concurrent_groups' => $groups, 'external_requests' => 0];
} catch (Throwable $error) {
    $report = ['passed' => false, 'cases' => $results, 'concurrent_groups' => $groups, 'failure' => $error->getMessage()];
    fwrite(STDERR, $error->getMessage()."\n");
}
if (! is_dir(dirname(__DIR__, 2).'/storage/framework/testing')) mkdir(dirname(__DIR__, 2).'/storage/framework/testing', 0700, true);
file_put_contents(dirname(__DIR__, 2).'/storage/framework/testing/push-mysql-results.json', json_encode($report, JSON_PRETTY_PRINT));
exit($report['passed'] ? 0 : 1);
