<?php

// This runner may recreate ONLY the disposable loopback CI database.
if (getenv('APP_ENV') !== 'testing' || getenv('DB_CONNECTION') !== 'mysql'
    || getenv('DB_DATABASE') !== 'cozy_release_test' || getenv('DB_USERNAME') !== 'root'
    || !in_array(getenv('DB_HOST'), ['127.0.0.1', 'localhost'], true) || getenv('DB_URL')) {
    throw new RuntimeException('Student concurrency runner requires the isolated CI database.');
}
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\{StudentApplication, User};
use App\Services\{StudentEligibility, StudentRenewalReminders};
use Illuminate\Support\Facades\{Artisan, DB, Http, Mail, Schema};
use Illuminate\Support\Str;

if (app()->environment() !== 'testing' || config('database.default') !== 'mysql'
    || config('database.connections.mysql.database') !== 'cozy_release_test'
    || config('database.connections.mysql.username') !== 'root'
    || !in_array(config('database.connections.mysql.host'), ['127.0.0.1', 'localhost'], true)
    || config('database.connections.mysql.url')) throw new RuntimeException('Resolved CI database failed isolation checks.');
Http::preventStrayRequests(); Mail::fake();
config(['launch.student.enabled' => true, 'cache.default' => 'array']);

function verifyStudent(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function requestStudent(User $user, string $path, array $data, bool $admin): int
{
    $token = $user->createToken($admin ? 'cozy-admin-management-v1' : 'student-race', $admin ? ['admin:subscriptions'] : ['*'], now()->addMinutes(5))->plainTextToken;
    $request = Illuminate\Http\Request::create('/api/v1/'.$path, 'POST', [], [], [],
        ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$token], json_encode($data));
    $kernel = app(Illuminate\Contracts\Http\Kernel::class);
    $response = $kernel->handle($request); $kernel->terminate($request, $response);
    return $response->getStatusCode();
}

if (($argv[1] ?? '') === 'worker') {
    [$script, $mode, $race, $worker, $userId, $applicationId] = $argv;
    DB::table('student_test_barriers')->insert(['race' => $race, 'worker' => $worker]);
    $deadline = microtime(true) + 10;
    while (DB::table('student_test_barriers')->where('race', $race)->count() < 2) {
        if (microtime(true) > $deadline) throw new RuntimeException('Student race barrier timed out.');
        usleep(10000);
    }
    $user = User::findOrFail($userId);
    if ($race === 'school') {
        $status = requestStudent($user, 'admin/student-school-requests/'.$applicationId.'/review', ['decision' => 'approved', 'note' => 'Synthetic Nigerian regulator and email domain checked', 'nigerian_tertiary_confirmed' => true, 'institution_type' => 'university'], true);
    } elseif ($race === 'review') {
        $status = requestStudent($user, 'admin/student-applications/'.$applicationId.'/review', ['decision' => 'approved', 'note' => 'Synthetic current-term evidence checked', 'current_enrolment_verified' => true], true);
    } elseif ($race === 'email') {
        $status = requestStudent($user, 'student-verification/email/confirm', ['id' => $applicationId, 'token' => str_repeat('a', 64)], false);
    } elseif ($race === 'claim') {
        $challenge = DB::table('student_email_challenges')->where('user_id', $user->id)->first();
        $status = requestStudent($user, 'student-verification/email/confirm', ['id' => $challenge->id, 'token' => str_repeat('a', 64)], false);
    } else {
        $status = app(StudentRenewalReminders::class)->tick();
    }
    echo json_encode(['status' => $status]); exit(0);
}

Artisan::call('migrate:fresh', ['--force' => true]);
Schema::create('student_test_barriers', function ($table) { $table->string('race'); $table->unsignedInteger('worker'); $table->primary(['race', 'worker']); });

function raceStudents(string $race, array $users, string $applicationId): array
{
    $processes = [];
    foreach ($users as $worker => $user) {
        $process = proc_open([PHP_BINARY, __FILE__, 'worker', $race, (string) $worker, (string) $user->id, $applicationId], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) throw new RuntimeException('Could not start Student race worker.');
        fclose($pipes[0]); $processes[] = [$process, $pipes];
    }
    $statuses = [];
    foreach ($processes as [$process, $pipes]) {
        $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        verifyStudent(proc_close($process) === 0, 'Student race worker failed: '.$error);
        $statuses[] = json_decode($output, true, flags: JSON_THROW_ON_ERROR)['status'];
    }
    sort($statuses); return $statuses;
}

function pendingStudent(User $user): StudentApplication
{
    return StudentApplication::create(['id' => (string) Str::uuid(), 'user_id' => $user->id, 'institution' => 'Synthetic College',
        'status' => 'pending', 'evidence_path' => 'student-test/nonexistent-private-fixture.pdf', 'evidence_delete_at' => now()->addDays(14)]);
}

function emailChallengeStudent(User $user): string
{
    $id = (string) Str::uuid();
    DB::table('student_email_challenges')->insert(['id' => $id, 'user_id' => $user->id,
        'email' => encrypt('learner@students.synthetic.example'), 'email_key' => StudentEligibility::emailKey('learner@students.synthetic.example'),
        'domain' => 'students.synthetic.example', 'token_hash' => hash('sha256', str_repeat('a', 64)), 'code_hash' => StudentEligibility::emailKey('123456'),
        'attempts' => 0, 'expires_at' => now()->addMinutes(15), 'created_at' => now(), 'updated_at' => now()]);
    return $id;
}

$student = User::factory()->create(); $admin = User::factory()->create(); $admin->forceFill(['role' => 'admin'])->save();
$application = pendingStudent($student);
verifyStudent(raceStudents('review', [$admin, $admin], $application->id) === [200, 409], 'Concurrent reviewers must commit exactly one approval.');
verifyStudent(DB::table('student_review_events')->where('application_id', $application->id)->count() === 1, 'Review race created duplicate decisions.');
DB::table('student_email_domains')->insert(['domain' => 'students.synthetic.example', 'institution' => 'Synthetic College', 'enabled' => true, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
$emailUser = User::factory()->create(); $challenge = emailChallengeStudent($emailUser);
verifyStudent(raceStudents('email', [$emailUser, $emailUser], $challenge) === [200, 422], 'Concurrent proof consumption must approve only once.');
verifyStudent(StudentApplication::where('user_id', $emailUser->id)->count() === 1, 'Proof race created duplicate eligibility.');
// Release only synthetic fixture claims so two different accounts can race the same email.
DB::table('student_email_claims')->delete();
$first = User::factory()->create(); $second = User::factory()->create(); emailChallengeStudent($first); emailChallengeStudent($second);
verifyStudent(raceStudents('claim', [$first, $second], '-') === [200, 422], 'One school email must not bind to two accounts.');
verifyStudent(DB::table('student_email_claims')->count() === 1, 'School email claim is not unique.');
$application->refresh()->update(['eligible_until' => now()->addDays(20)]);
verifyStudent(raceStudents('reminder', [$student, $student], $application->id) === [0, 1], 'Concurrent schedulers must enqueue only one reminder.');
verifyStudent(DB::table('student_renewal_reminders')->count() === 1 && DB::table('jobs')->where('queue', 'email')->count() === 1, 'Reminder outbox or database queue contains duplicates.');
$schoolId = (string) Str::uuid();
DB::table('student_school_requests')->insert(['id' => $schoolId, 'user_id' => $student->id, 'domain' => 'school.synthetic.example', 'institution' => 'Synthetic Nigerian School', 'website' => 'https://school.synthetic.example', 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
verifyStudent(raceStudents('school', [$admin, $admin], $schoolId) === [200, 409], 'Concurrent school reviewers must approve exactly once.');
verifyStudent(DB::table('student_email_domains')->where('domain', 'school.synthetic.example')->count() === 1, 'School review must create exactly one approved domain.');

echo "PASS: concurrent reviews, email proof, school email claims and reminder dispatch (MySQL).\n";
