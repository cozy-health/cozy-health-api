<?php

use App\Mail\BrandedNoticeMail;
use App\Mail\ProviderPasswordResetMail;
use App\Mail\PasswordResetRequestMail;
use App\Mail\SignInChallengeMail;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.brevo.webhook_token' => str_repeat('b', 32)]);
});

function bouncedOwner(string $role): User|Provider
{
    return $role === 'provider'
        ? Provider::create(['name' => 'Bounce fixture', 'email' => 'provider@example.test', 'password_hash' => 'SecretChoice947!'])
        : User::factory()->create(['email' => 'patient@example.test', 'password' => 'SecretChoice947!']);
}

function bounceEvent(string $email, string $event = 'hardBounce'): array
{
    return ['event' => $event, 'email' => strtoupper($email), 'message-id' => 'bounce-fixture-message', 'date' => now()->toIso8601String()];
}

it('records provider hard bounces once without retaining the address', function () {
    $owner = bouncedOwner('provider');
    $payload = bounceEvent($owner->email);
    foreach ([1, 2] as $attempt) {
        $this->withToken(str_repeat('b', 32))->postJson('/webhooks/brevo', $payload)->assertOk();
    }
    expect($owner->fresh()->email_invalid)->toBeTrue();
    $this->assertDatabaseCount('bounces', 1);
    $this->assertDatabaseHas('bounces', ['provider_id' => $owner->id, 'user_id' => null, 'event' => 'hard_bounce']);
    expect(json_encode(DB::table('bounces')->get()))->not->toContain($owner->email, strtoupper($owner->email));
});

it('does not send provider resets to a permanently bounced address or disclose the account', function () {
    Mail::fake();
    $owner = bouncedOwner('provider');
    $this->withToken(str_repeat('b', 32))->postJson('/webhooks/brevo', bounceEvent($owner->email))->assertOk();
    $blocked = $this->postJson('/api/v1/provider/auth/forgot-password', ['email' => $owner->email])->assertOk();
    $unknown = $this->postJson('/api/v1/provider/auth/forgot-password', ['email' => 'unknown@example.test'])->assertOk();
    expect($blocked->json())->toBe($unknown->json());
    Mail::assertNothingOutgoing();
    $this->assertDatabaseCount('provider_password_reset_tokens', 0);
});

it('discards queued sign-in and reset codes if the mailbox bounces before dispatch', function (string $role) {
    Mail::fake();
    config(['signin_security.enabled' => true]);
    $owner = bouncedOwner($role);
    $path = $role === 'provider' ? '/api/v1/provider/auth' : '/api/v1/auth';
    $this->postJson($path.'/login', ['email' => $owner->email, 'password' => 'SecretChoice947!'])
        ->assertOk()->assertJsonPath('data.email_delivery', 'queued');
    $signin = Mail::queued(SignInChallengeMail::class)->last();
    expect($signin->shouldSend())->toBeTrue();
    $table = $role === 'provider' ? 'provider_password_reset_tokens' : 'password_reset_tokens';
    DB::table($table)->insert(['email' => $owner->email, 'token' => Hash::make('543210'), 'created_at' => now(), 'expires_at' => now()->addMinutes(15)]);
    $reset = $role === 'provider' ? new ProviderPasswordResetMail($owner, '543210') : new PasswordResetRequestMail($owner, '543210');
    expect($reset->shouldSend())->toBeTrue();
    $this->withToken(str_repeat('b', 32))->postJson('/webhooks/brevo', bounceEvent($owner->email))->assertOk();
    expect($signin->shouldSend())->toBeFalse();
    expect($reset->shouldSend())->toBeFalse();
    $this->postJson($path.'/login', ['email' => $owner->email, 'password' => 'SecretChoice947!'])
        ->assertOk()->assertJsonPath('data.email_delivery', 'unavailable')->assertJsonMissingPath('data.token');
    Mail::assertQueued(SignInChallengeMail::class, 1);
})->with(['user', 'provider']);

it('suppresses every tracked email to a bounced recipient at the transport boundary', function (string $role) {
    $owner = bouncedOwner($role);
    config(['mail.default' => 'array', 'mail.mailers.array' => ['transport' => 'array']]);
    $this->withToken(str_repeat('b', 32))->postJson('/webhooks/brevo', bounceEvent($owner->email))->assertOk();
    $mail = new BrandedNoticeMail('Fixture notice', 'Fixture notice', ['Synthetic content.']);
    Mail::to($owner->email)->send($mail);
    expect(Mail::mailer('array')->getSymfonyTransport()->messages())->toHaveCount(0);
    $this->assertDatabaseHas('email_delivery_logs', ['id' => $mail->deliveryId, 'status' => 'suppressed']);
    expect(json_encode(DB::table('email_delivery_logs')->get()))->not->toContain($owner->email, 'Synthetic content.');
})->with(['user', 'provider']);

it('keeps shared-mailbox bounce evidence separate when either role is erased', function () {
    $user = User::factory()->create(['email' => 'shared@example.test']);
    $provider = Provider::create(['name' => 'Shared address fixture', 'email' => $user->email]);
    $other = Provider::create(['name' => 'Unrelated fixture', 'email' => 'other@example.test']);
    $payload = bounceEvent($user->email);
    foreach ([1, 2] as $attempt) {
        $this->withToken(str_repeat('b', 32))->postJson('/webhooks/brevo', $payload)->assertOk();
    }
    expect((bool) $user->fresh()->email_invalid)->toBeTrue();
    expect($provider->fresh()->email_invalid)->toBeTrue();
    expect($other->fresh()->email_invalid)->toBeFalse();
    $this->assertDatabaseCount('bounces', 2);
    $user->forceDelete();
    $this->withToken(str_repeat('b', 32))->postJson('/webhooks/brevo', $payload)->assertOk();
    $this->assertDatabaseCount('bounces', 1);
    $this->assertDatabaseHas('bounces', ['provider_id' => $provider->id, 'user_id' => null]);
    $provider->delete();
    $this->assertDatabaseCount('bounces', 0);
});

it('keeps optional-mail unsubscribe and temporary failures separate from authentication delivery', function (string $event) {
    Mail::fake();
    $owner = bouncedOwner('provider');
    $this->withToken(str_repeat('b', 32))->postJson('/webhooks/brevo', bounceEvent($owner->email, $event))->assertOk();
    expect($owner->fresh()->email_invalid)->toBeFalse();
    expect($owner->fresh()->email_unsubscribed_at !== null)->toBe(in_array($event, ['spam', 'unsubscribe'], true));
    $this->postJson('/api/v1/provider/auth/forgot-password', ['email' => $owner->email])->assertOk();
    Mail::assertQueued(ProviderPasswordResetMail::class, 1);
})->with(['softBounce', 'spam', 'unsubscribe']);
