<?php
use App\Models\User;
use App\Mail\VerifyEmailMail;
use App\Mail\AccountDeletedMail;
use App\Mail\PasswordResetRequestMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
uses(RefreshDatabase::class);

beforeEach(function () { Mail::fake(); config(['services.brevo.webhook_token' => str_repeat('t', 32)]); });

function brevoPayload(string $email, string $event = 'hardBounce'): array
{
    return ['event' => $event, 'email' => $email, 'message-id' => 'delivery-id', 'date' => '2026-10-08T12:00:00Z'];
}

it('fails closed for missing invalid or unconfigured webhook tokens', function () {
    $payload = brevoPayload('example@example.org');
    $this->postJson('/webhooks/brevo', $payload)->assertUnauthorized();
    $this->withToken('wrong')->postJson('/webhooks/brevo', $payload)->assertUnauthorized();
    config(['services.brevo.webhook_token' => null]);
    $this->withToken(str_repeat('t', 32))->postJson('/webhooks/brevo', $payload)->assertUnauthorized();
    $this->assertDatabaseCount('bounces', 0);
});

it('records a hard bounce once and marks the destination invalid', function () {
    $user = User::factory()->create(); $payload = brevoPayload($user->email);
    foreach ([1, 2] as $_) { $this->withToken(str_repeat('t', 32))->postJson('/webhooks/brevo', $payload)->assertOk(); }
    $this->assertDatabaseCount('bounces', 1);
    expect((bool) $user->fresh()->email_invalid)->toBeTrue();
    expect(json_encode(DB::table('bounces')->first()))->not->toContain($user->email);
});

it('handles spam unsubscribe and soft bounce without invalidating email', function (string $event) {
    $user = User::factory()->create();
    $this->withToken(str_repeat('t', 32))->postJson('/webhooks/brevo', brevoPayload($user->email, $event))->assertOk();
    expect((bool) $user->fresh()->email_invalid)->toBeFalse();
    expect($user->fresh()->email_unsubscribed_at !== null)->toBe(in_array($event, ['spam', 'unsubscribe']));
})->with(['spam', 'unsubscribe', 'softBounce']);

it('validates authenticated webhook payloads', function () {
    $this->withToken(str_repeat('t', 32))->postJson('/webhooks/brevo', ['event' => 'spam'])->assertUnprocessable();
});

it('limits reset email retries across ips and allows retries after fifteen minutes', function () {
    $this->freezeTime();
    $user = User::factory()->create();
    for ($i = 1; $i <= 5; $i++) {
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.$i])->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertOk();
        $this->travel(61)->seconds();
    }
    $blocked = $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.6'])->postJson('/api/v1/auth/forgot-password', ['email' => strtoupper($user->email)])->assertTooManyRequests();
    expect((int) $blocked->headers->get('Retry-After'))->toBeGreaterThan(0)->toBeLessThanOrEqual(900);
    Mail::assertQueued(PasswordResetRequestMail::class, 5);
    $this->travel(15)->minutes();
    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.7'])->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertOk();
    Mail::assertQueued(PasswordResetRequestMail::class, 6);
});

it('does not send reset emails to hard bounced addresses', function () {
    $user = User::factory()->create(); $user->forceFill(['email_invalid' => true])->save();
    $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertOk();
    Mail::assertNothingOutgoing();
});

it('queues and consumes a hashed expiring verification code', function () {
    $user = User::factory()->create(['email_verified_at' => null]); Sanctum::actingAs($user);
    $this->postJson('/api/v1/auth/email-verification/resend')->assertOk();
    $code = null;
    Mail::assertQueued(VerifyEmailMail::class, function ($mail) use (&$code) { $code = $mail->code; return true; });
    $record = DB::table('email_verification_codes')->where('user_id', $user->id)->first();
    expect($record->code_hash)->not->toBe($code);
    $this->postJson('/api/v1/auth/email-verification/verify', ['code' => $code])->assertOk();
    expect($user->fresh()->email_verified_at)->not->toBeNull();
    $this->assertDatabaseCount('email_verification_codes', 0);
    $this->postJson('/api/v1/auth/email-verification/verify', ['code' => $code])->assertUnprocessable();
});

it('rejects expired verification codes and throttles resend per user', function () {
    $user = User::factory()->create(['email_verified_at' => null]); Sanctum::actingAs($user);
    DB::table('email_verification_codes')->insert(['user_id' => $user->id, 'code_hash' => Hash::make('123456'), 'expires_at' => now()->subMinute()]);
    $this->postJson('/api/v1/auth/email-verification/verify', ['code' => '123456'])->assertUnprocessable();
    for ($i = 0; $i < 3; $i++) { $this->postJson('/api/v1/auth/email-verification/resend')->assertOk(); }
    $this->postJson('/api/v1/auth/email-verification/resend')->assertTooManyRequests();
});

it('queues deletion confirmation after permanent erasure and removes delivery records', function () {
    $user = User::factory()->create(['email_verified_at' => null]); Sanctum::actingAs($user);
    DB::table('bounces')->insert(['event_key' => str_repeat('a', 64), 'user_id' => $user->id, 'event' => 'soft_bounce', 'occurred_at' => now(), 'created_at' => now()]);
    $this->deleteJson('/api/v1/account')->assertOk();
    $this->assertDatabaseMissing('users', ['id' => $user->id]);
    $this->assertDatabaseCount('bounces', 0);
    Mail::assertQueued(AccountDeletedMail::class);
});

it('provides rendered html and plain text alternatives for every transactional template', function (string $template, array $data) {
    expect(view('emails.'.$template, $data)->render())->toContain('Cozy Health');
    $text = view('emails.'.$template.'-text', $data)->render();
    expect($text)->toContain('Cozy Health')->not->toContain('<html');
})->with([
    ['auth.password-reset', ['token' => '123456']], ['auth.password-reset-confirmation', []],
    ['auth.verify-email', ['code' => '123456']], ['auth.welcome', []],
    ['account.deleted', []], ['quiz.share', ['reviewUrl' => 'https://cozyhealth.org/review/test']],
]);
