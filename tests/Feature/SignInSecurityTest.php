<?php

use App\Mail\SignInChallengeMail;
use App\Models\Provider;
use App\Models\User;
use App\Services\AuthSessions;
use App\Services\SignInSecurity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['signin_security.enabled' => true]);
    Mail::fake();
});

function signinOwner(string $role): User|Provider
{
    return $role === 'provider'
        ? Provider::create(['name' => 'Provider', 'email' => 'provider@example.test', 'password_hash' => 'SecretChoice947!'])
        : User::factory()->create(['password' => 'SecretChoice947!']);
}

function signinPath(string $role): string
{
    return $role === 'provider' ? '/api/v1/provider/auth' : '/api/v1/auth';
}

function signinCode(): string
{
    return Mail::queued(SignInChallengeMail::class)->last()->code;
}

it('requires email proof before issuing a new device session and rejects challenge replay', function (string $role) {
    $owner = signinOwner($role);
    $path = signinPath($role);
    $pending = $this->postJson($path.'/login', ['email' => $owner->email, 'password' => 'SecretChoice947!'])
        ->assertOk()->assertJsonPath('data.challenge_required', true)->assertJsonMissingPath('data.token')->json('data');
    expect($owner->tokens()->count())->toBe(0);
    expect(DB::table('signin_challenges')->value('token_hash'))->not->toBe($pending['challenge_token']);
    $this->withToken($pending['challenge_token'])->getJson($role === 'provider' ? '/api/v1/provider/me' : '/api/v1/me')->assertUnauthorized();
    $data = ['challenge_token' => $pending['challenge_token'], 'code' => signinCode()];
    $session = $this->postJson($path.'/challenge/verify', $data)->assertOk()->assertJsonPath('data.challenge_required', false)
        ->assertJsonStructure(['data' => ['token', 'refresh_token', 'device_proof', 'security_settings_token']])->json('data');
    expect($owner->tokens()->count())->toBe(1);
    expect($session[$role === 'provider' ? 'provider' : 'user']['id'])->toBe($owner->id);
    expect($session['security_enrollment_required'])->toBeTrue();
    app('auth')->forgetGuards();
    $this->withToken($session['token'])->putJson($path.'/security', ['method' => 'email',
        'security_settings_token' => $session['security_settings_token']])->assertOk()->assertJsonPath('data.enrolled', true);
    $this->postJson($path.'/challenge/verify', $data)->assertUnprocessable();
    $this->postJson($path.'/login', ['email' => $owner->email, 'password' => 'SecretChoice947!', 'device_proof' => $session['device_proof']])
        ->assertOk()->assertJsonStructure(['data' => ['token']])->assertJsonMissingPath('data.challenge_token');
})->with(['user', 'provider']);

it('does not treat a claimed device id as trusted proof', function () {
    $owner = signinOwner('user');
    DB::table('known_devices')->insert(['owner_type' => $owner->getMorphClass(), 'owner_id' => $owner->id,
        'device_hash' => hash('sha256', 'claimed-device'), 'created_at' => now()]);
    $this->postJson('/api/v1/auth/login', ['email' => $owner->email, 'password' => 'SecretChoice947!', 'device_id' => 'claimed-device'])
        ->assertOk()->assertJsonPath('data.challenge_required', true)->assertJsonMissingPath('data.token');
});

it('persists failed guesses and exhausts a code after five attempts across ips', function () {
    $owner = signinOwner('user');
    $pending = $this->postJson('/api/v1/auth/login', ['email' => $owner->email, 'password' => 'SecretChoice947!'])->json('data');
    $code = signinCode();
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.($attempt + 1)])
            ->postJson('/api/v1/auth/challenge/verify', ['challenge_token' => $pending['challenge_token'], 'code' => '000000'])->assertUnprocessable();
    }
    expect(DB::table('signin_challenges')->value('failed_attempts'))->toBe(5);
    $this->postJson('/api/v1/auth/challenge/verify', ['challenge_token' => $pending['challenge_token'], 'code' => $code])->assertUnprocessable();
    expect($owner->tokens()->count())->toBe(0);
});

it('expires challenges and rejects a challenge through the other role endpoint', function () {
    $owner = signinOwner('user');
    $pending = $this->postJson('/api/v1/auth/login', ['email' => $owner->email, 'password' => 'SecretChoice947!'])->json('data');
    $body = ['challenge_token' => $pending['challenge_token'], 'code' => signinCode()];
    $this->postJson('/api/v1/provider/auth/challenge/verify', $body)->assertUnprocessable();
    $this->travel(10)->minutes();
    $this->postJson('/api/v1/auth/challenge/verify', $body)->assertUnprocessable();
});

it('enrolls hashed questions after email verification and supports forgotten-answer email recovery', function (string $role) {
    $owner = signinOwner($role);
    $path = signinPath($role);
    $pending = $this->postJson($path.'/login', ['email' => $owner->email, 'password' => 'SecretChoice947!'])->json('data');
    $session = $this->postJson($path.'/challenge/verify', ['challenge_token' => $pending['challenge_token'], 'code' => signinCode()])->assertOk()->json('data');
    $questions = [['id' => 'memorable_place', 'answer' => 'Quiet Beach'], ['id' => 'memorable_book', 'answer' => 'Hidden Garden'], ['id' => 'memorable_word', 'answer' => 'Silver cloud']];
    app('auth')->forgetGuards();
    $this->withToken($session['token'])->putJson($path.'/security', ['method' => 'questions', 'questions' => $questions,
        'security_settings_token' => $session['security_settings_token']])->assertOk()->assertJsonPath('data.method', 'questions')->assertJsonPath('data.enrolled', true);
    $stored = json_decode(DB::table('signin_security_preferences')->value('questions'), true);
    expect(Hash::check(hash('sha256', 'quiet beach'), $stored['memorable_place']))->toBeTrue();
    expect(json_encode($stored))->not->toContain('Quiet Beach');
    $this->putJson($path.'/security', ['method' => 'email', 'security_settings_token' => $session['security_settings_token']])->assertForbidden();
    $challenge = $this->postJson($path.'/login', ['email' => $owner->email, 'password' => 'SecretChoice947!'])
        ->assertOk()->assertJsonPath('data.method', 'questions')->assertJsonCount(2, 'data.questions')->json('data');
    $answers = ['memorable_place' => '  QUIET  beach ', 'memorable_book' => 'hidden garden', 'memorable_word' => 'silver cloud'];
    $selected = array_intersect_key($answers, array_flip(array_column($challenge['questions'], 'id')));
    $this->postJson($path.'/challenge/verify', ['challenge_token' => $challenge['challenge_token'], 'answers' => $selected])
        ->assertOk()->assertJsonStructure(['data' => ['token']]);
    $another = $this->postJson($path.'/login', ['email' => $owner->email, 'password' => 'SecretChoice947!'])->json('data');
    $this->postJson($path.'/challenge/resend', ['challenge_token' => $another['challenge_token']])
        ->assertOk()->assertJsonPath('data.method', 'email')->assertJsonPath('data.support_email', 'team@cozyhealth.org');
    $this->postJson($path.'/challenge/verify', ['challenge_token' => $another['challenge_token'], 'code' => signinCode()])
        ->assertOk()->assertJsonStructure(['data' => ['token', 'security_settings_token']]);
})->with(['user', 'provider']);

it('requires another challenge after fourteen days of device inactivity and on refresh without proof', function () {
    $owner = signinOwner('user');
    $pending = $this->postJson('/api/v1/auth/login', ['email' => $owner->email, 'password' => 'SecretChoice947!'])->json('data');
    $session = $this->postJson('/api/v1/auth/challenge/verify', ['challenge_token' => $pending['challenge_token'], 'code' => signinCode()])->json('data');
    $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $session['refresh_token']])->assertOk()
        ->assertJsonPath('data.challenge_required', true)->assertJsonMissingPath('data.token');
    $this->travel(14)->days();
    $this->postJson('/api/v1/auth/login', ['email' => $owner->email, 'password' => 'SecretChoice947!', 'device_proof' => $session['device_proof']])
        ->assertOk()->assertJsonPath('data.challenge_required', true);
});

it('does not send queued codes after account email changes or the challenge is superseded', function () {
    $owner = signinOwner('user');
    $pending = $this->postJson('/api/v1/auth/login', ['email' => $owner->email, 'password' => 'SecretChoice947!'])->json('data');
    $mail = Mail::queued(SignInChallengeMail::class)->last();
    expect($mail->shouldSend())->toBeTrue();
    $this->travel(61)->seconds();
    $this->postJson('/api/v1/auth/challenge/resend', ['challenge_token' => $pending['challenge_token']])->assertOk();
    expect($mail->shouldSend())->toBeFalse();
    $newMail = Mail::queued(SignInChallengeMail::class)->last();
    $owner->forceFill(['email' => 'changed@example.test'])->save();
    expect($newMail->shouldSend())->toBeFalse();
});

it('blocks legacy sessions and keeps verified sessions and emergency access working', function (string $role) {
    $owner = signinOwner($role);
    $path = signinPath($role);
    $private = $role === 'provider' ? '/api/v1/provider/me' : '/api/v1/me';
    $legacy = $owner->createToken('legacy')->plainTextToken;
    $this->withToken($legacy)->getJson($private)->assertForbidden()->assertJsonPath('code', 'signin_verification_required');
    if ($role === 'user') {
        $this->getJson('/api/v1/crisis/resources')->assertOk();
    }
    $challenge = $this->postJson($path.'/login', ['email' => $owner->email, 'password' => 'SecretChoice947!'])->json('data');
    $session = $this->postJson($path.'/challenge/verify', ['challenge_token' => $challenge['challenge_token'], 'code' => signinCode()])->assertOk()->json('data');
    app('auth')->forgetGuards();
    $this->withToken($session['token'])->putJson($path.'/security', ['method' => 'email',
        'security_settings_token' => $session['security_settings_token']])->assertOk()->assertJsonPath('data.enrolled', true);
    $this->getJson($private)->assertOk();
})->with(['user', 'provider']);

it('does not accept another accounts trusted device proof or settings ticket', function () {
    $owner = signinOwner('user');
    $other = User::factory()->create(['password' => 'SecretChoice947!']);
    $challenge = $this->postJson('/api/v1/auth/login', ['email' => $owner->email, 'password' => 'SecretChoice947!'])->json('data');
    $session = $this->postJson('/api/v1/auth/challenge/verify', ['challenge_token' => $challenge['challenge_token'], 'code' => signinCode()])->json('data');
    $this->postJson('/api/v1/auth/login', ['email' => $other->email, 'password' => 'SecretChoice947!', 'device_proof' => $session['device_proof']])
        ->assertOk()->assertJsonPath('data.challenge_required', true);
    app('auth')->forgetGuards();
    $this->withToken($other->createToken('legacy')->plainTextToken)->putJson('/api/v1/auth/security', [
        'method' => 'email', 'security_settings_token' => $session['security_settings_token'],
    ])->assertForbidden();
});

it('clears trusted devices on password recovery and prunes expired challenges', function () {
    $owner = signinOwner('user');
    $challenge = $this->postJson('/api/v1/auth/login', ['email' => $owner->email, 'password' => 'SecretChoice947!'])->json('data');
    $session = $this->postJson('/api/v1/auth/challenge/verify', ['challenge_token' => $challenge['challenge_token'], 'code' => signinCode()])->json('data');
    app(AuthSessions::class)->revoke($owner);
    expect(app(SignInSecurity::class)->trustedHash($owner, $session['device_proof']))->toBeNull();
    $this->travel(21)->minutes();
    $this->artisan('auth:prune-challenges')->assertExitCode(0);
    expect(DB::table('signin_challenges')->count())->toBe(0);
});

it('does not issue a challenge for an incorrect password or accept malformed device proof', function () {
    $owner = signinOwner('user');
    $this->postJson('/api/v1/auth/login', ['email' => $owner->email, 'password' => 'Wrong'])->assertUnprocessable();
    $this->postJson('/api/v1/auth/login', ['email' => $owner->email, 'password' => 'SecretChoice947!', 'device_proof' => ['invalid']])->assertUnprocessable();
    expect(DB::table('signin_challenges')->count())->toBe(0);
    Mail::assertNothingOutgoing();
});

it('reports email queue failure without issuing private credentials', function (string $role) {
    $owner = signinOwner($role);
    Mail::shouldReceive('to')->once()->with($owner->email)->andThrow(new RuntimeException('Synthetic queue failure'));
    $this->postJson(signinPath($role).'/login', ['email' => $owner->email, 'password' => 'SecretChoice947!'])
        ->assertOk()->assertJsonPath('data.challenge_required', true)->assertJsonPath('data.email_delivery', 'failed')->assertJsonMissingPath('data.token');
    expect($owner->tokens()->count())->toBe(0);
})->with(['user', 'provider']);

it('rejects blank normalized answers and checks the entire long answer', function () {
    $owner = signinOwner('user');
    $challenge = $this->postJson('/api/v1/auth/login', ['email' => $owner->email, 'password' => 'SecretChoice947!'])->json('data');
    $session = $this->postJson('/api/v1/auth/challenge/verify', ['challenge_token' => $challenge['challenge_token'], 'code' => signinCode()])->json('data');
    app('auth')->forgetGuards();
    $this->withToken($session['token']);
    $ids = ['memorable_place', 'memorable_book', 'memorable_word'];
    $blank = array_map(fn ($id) => ['id' => $id, 'answer' => '   '], $ids);
    $this->putJson('/api/v1/auth/security', ['method' => 'questions', 'questions' => $blank,
        'security_settings_token' => $session['security_settings_token']])->assertUnprocessable();
    $questions = array_map(fn ($id) => ['id' => $id, 'answer' => str_repeat('x', 72).$id], $ids);
    $this->putJson('/api/v1/auth/security', ['method' => 'questions', 'questions' => $questions,
        'security_settings_token' => $session['security_settings_token']])->assertOk();
    $pending = $this->postJson('/api/v1/auth/login', ['email' => $owner->email, 'password' => 'SecretChoice947!'])->json('data');
    $wrong = $correct = [];
    foreach ($pending['questions'] as $question) {
        $wrong[$question['id']] = str_repeat('x', 72).'incorrect suffix';
        $correct[$question['id']] = str_repeat('x', 72).$question['id'];
    }
    $this->postJson('/api/v1/auth/challenge/verify', ['challenge_token' => $pending['challenge_token'], 'answers' => $wrong])->assertUnprocessable();
    $this->postJson('/api/v1/auth/challenge/verify', ['challenge_token' => $pending['challenge_token'], 'answers' => $correct])->assertOk();
});

it('keeps private APIs and trusted-device reuse locked until protection enrollment is confirmed', function (string $role) {
    $owner = signinOwner($role);
    $path = signinPath($role);
    $private = $role === 'provider' ? '/api/v1/provider/me' : '/api/v1/me';
    $pending = $this->postJson($path.'/login', ['email' => $owner->email, 'password' => 'SecretChoice947!'])->json('data');
    $session = $this->postJson($path.'/challenge/verify', ['challenge_token' => $pending['challenge_token'], 'code' => signinCode()])->assertOk()->json('data');
    app('auth')->forgetGuards();
    $this->withToken($session['token'])->getJson($private)->assertForbidden()->assertJsonPath('code', 'signin_verification_required');
    $this->getJson($path.'/security')->assertOk()->assertJsonPath('data.enrolled', false);
    $this->postJson($path.'/login', ['email' => $owner->email, 'password' => 'SecretChoice947!', 'device_proof' => $session['device_proof']])
        ->assertOk()->assertJsonPath('data.challenge_required', true)->assertJsonMissingPath('data.token');
    app('auth')->forgetGuards();
    $this->withToken($session['token'])->putJson($path.'/security', ['method' => 'email', 'security_settings_token' => $session['security_settings_token']])
        ->assertOk()->assertJsonPath('data.enrolled', true);
    $this->getJson($private)->assertOk();
})->with(['user', 'provider']);
it('preserves enrolled account protection when new enrollment rollout is paused', function (string $role) {
    $owner = signinOwner($role);
    $path = signinPath($role);
    $private = $role === 'provider' ? '/api/v1/provider/me' : '/api/v1/me';
    $pending = $this->postJson($path.'/login', ['email'=>$owner->email,'password'=>'SecretChoice947!'])->json('data');
    $session = $this->postJson($path.'/challenge/verify', ['challenge_token'=>$pending['challenge_token'],'code'=>signinCode()])->assertOk()->json('data');
    $questions = [['id'=>'memorable_place','answer'=>'Quiet Beach'],['id'=>'memorable_book','answer'=>'Hidden Garden'],['id'=>'memorable_word','answer'=>'Silver cloud']];
    app('auth')->forgetGuards();
    $this->withToken($session['token'])->putJson($path.'/security', ['method'=>'questions','questions'=>$questions,'security_settings_token'=>$session['security_settings_token']])->assertOk();
    $legacy = $owner->createToken('legacy-without-device-proof')->plainTextToken;
    config(['signin_security.enabled'=>false]);
    app('auth')->forgetGuards();
    $this->withToken($legacy)->getJson($private)->assertForbidden()->assertJsonPath('code','signin_verification_required');
    app('auth')->forgetGuards();
    $this->withToken($session['token'])->getJson($path.'/security')->assertOk()->assertJsonPath('data.enabled',true)->assertJsonPath('data.enrolled',true);
    $this->getJson($private)->assertOk();
    $next = $this->postJson($path.'/login', ['email'=>$owner->email,'password'=>'SecretChoice947!'])->assertOk()->assertJsonPath('data.method','questions')->assertJsonMissingPath('data.token')->json('data');
    $answers = array_column($questions,'answer','id');
    $this->postJson($path.'/challenge/verify', ['challenge_token'=>$next['challenge_token'],'answers'=>array_intersect_key($answers,array_flip(array_column($next['questions'],'id')))])->assertOk()->assertJsonPath('data.security_enrollment_required',false);
})->with(['user','provider']);

it('can finish a pending email enrollment after rollout pauses without exposing private content', function (string $role) {
    $owner = signinOwner($role);
    $path = signinPath($role);
    $private = $role === 'provider' ? '/api/v1/provider/me' : '/api/v1/me';
    $pending = $this->postJson($path.'/login', ['email'=>$owner->email,'password'=>'SecretChoice947!'])->json('data');
    $code = signinCode();
    config(['signin_security.enabled'=>false]);
    $this->travel(61)->seconds();
    $this->postJson($path.'/challenge/resend', ['challenge_token'=>$pending['challenge_token']])->assertOk();
    $code = signinCode();
    $session = $this->postJson($path.'/challenge/verify', ['challenge_token'=>$pending['challenge_token'],'code'=>$code])->assertOk()->assertJsonPath('data.security_enrollment_required',true)->json('data');
    app('auth')->forgetGuards();
    $this->withToken($session['token'])->getJson($private)->assertForbidden();
    $this->getJson($path.'/security')->assertOk()->assertJsonPath('data.enabled',true)->assertJsonPath('data.enrolled',false);
    $this->putJson($path.'/security', ['method'=>'email','security_settings_token'=>$session['security_settings_token']])->assertOk()->assertJsonPath('data.enrolled',true);
    $this->getJson($private)->assertOk();
})->with(['user','provider']);
it('leaves untouched accounts on the existing sign-in path while new enrollment rollout is paused', function (string $role) {
    config(['signin_security.enabled'=>false]);
    $owner = signinOwner($role);
    $path = signinPath($role);
    $private = $role === 'provider' ? '/api/v1/provider/me' : '/api/v1/me';
    $session = $this->postJson($path.'/login', ['email'=>$owner->email,'password'=>'SecretChoice947!'])->assertOk()->assertJsonMissingPath('data.challenge_token')->json('data');
    app('auth')->forgetGuards();
    $this->withToken($session['token'])->getJson($private)->assertOk();
    $this->getJson($path.'/security')->assertOk()->assertJsonPath('data.enabled',false)->assertJsonPath('data.enrolled',false);
    $this->postJson($path.'/security/reauthenticate', ['password'=>'SecretChoice947!'])->assertStatus(503);
    expect(DB::table('signin_security_preferences')->count())->toBe(0);
    Mail::assertNothingQueued();
})->with(['user','provider']);

it('does not reopen a rejected legacy session when rollout pauses before enrollment starts', function (string $role) {
    $owner = signinOwner($role);
    $path = signinPath($role);
    $private = $role === 'provider' ? '/api/v1/provider/me' : '/api/v1/me';
    $legacy = $owner->createToken('legacy')->plainTextToken;
    $this->withToken($legacy)->getJson($private)->assertForbidden();
    config(['signin_security.enabled'=>false]);
    app('auth')->forgetGuards();
    $this->withToken($legacy)->getJson($private)->assertForbidden();
    Mail::assertNothingQueued();
    $this->postJson($path.'/login', ['email'=>$owner->email,'password'=>'SecretChoice947!'])->assertOk()->assertJsonPath('data.challenge_required',true)->assertJsonMissingPath('data.token');
})->with(['user','provider']);
it('keeps refresh device-proof checks when enrollment rollout is paused', function (string $role) {
    $owner = signinOwner($role);
    $path = signinPath($role);
    $private = $role === 'provider' ? '/api/v1/provider/me' : '/api/v1/me';
    $pending = $this->postJson($path.'/login', ['email'=>$owner->email,'password'=>'SecretChoice947!'])->json('data');
    $session = $this->postJson($path.'/challenge/verify', ['challenge_token'=>$pending['challenge_token'],'code'=>signinCode()])->assertOk()->json('data');
    app('auth')->forgetGuards();
    $this->withToken($session['token'])->putJson($path.'/security', ['method'=>'email','security_settings_token'=>$session['security_settings_token']])->assertOk();
    config(['signin_security.enabled'=>false]);
    $renewed = $this->postJson($path.'/refresh', ['refresh_token'=>$session['refresh_token'],'device_proof'=>$session['device_proof']])->assertOk()->assertJsonMissingPath('data.challenge_token')->json('data');
    app('auth')->forgetGuards();
    $this->withToken($renewed['token'])->getJson($private)->assertOk();
    $this->postJson($path.'/refresh', ['refresh_token'=>$renewed['refresh_token']])->assertOk()->assertJsonPath('data.challenge_required',true)->assertJsonMissingPath('data.token');
    app('auth')->forgetGuards();
    $this->withToken($renewed['token'])->getJson($private)->assertUnauthorized();
})->with(['user','provider']);
it('preserves a pending challenge created by a build without the enrollment marker', function (string $role) {
    $owner = signinOwner($role);
    $path = signinPath($role);
    $this->postJson($path.'/login', ['email'=>$owner->email,'password'=>'SecretChoice947!'])->assertOk()->assertJsonPath('data.challenge_required',true);
    // Reproduce the earlier staged schema usage: a challenge existed before a preference row.
    DB::table('signin_security_preferences')->where('owner_type',$owner->getMorphClass())->where('owner_id',$owner->id)->delete();
    config(['signin_security.enabled'=>false]);
    $legacy = $owner->createToken('legacy')->plainTextToken;
    app('auth')->forgetGuards();
    $this->withToken($legacy)->getJson($path.'/security')->assertOk()->assertJsonPath('data.enabled',true)->assertJsonPath('data.enrolled',false);
    $this->postJson($path.'/login', ['email'=>$owner->email,'password'=>'SecretChoice947!'])->assertOk()->assertJsonPath('data.challenge_required',true)->assertJsonMissingPath('data.token');
    expect(DB::table('signin_security_preferences')->where('owner_type',$owner->getMorphClass())->where('owner_id',$owner->id)->exists())->toBeTrue();
})->with(['user','provider']);