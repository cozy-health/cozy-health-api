<?php

use App\Mail\BrandedNoticeMail;
use App\Models\User;
use App\Services\CommunityAvailability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['development_mail.project_id' => CommunityAvailability::DEVELOPMENT_PROJECT,
        'development_mail.environment_id' => CommunityAvailability::DEVELOPMENT_ENVIRONMENT,
        'app.url' => 'https://api-dev.cozyhealth.org',
        'development_mail.allowed_recipients' => ['approved@example.test'],
        'services.brevo.webhook_token' => str_repeat('d', 32),
        'mail.default' => 'array', 'mail.mailers.array' => ['transport' => 'array']]);
});

it('marks actual transported development messages and preserves existing metadata', function (?string $existing) {
    $mail = new BrandedNoticeMail('Fixture', 'Fixture', ['Synthetic content.']);
    if ($existing !== null) {
        $mail->withSymfonyMessage(fn ($message) => $message->getHeaders()->addTextHeader('X-Mailin-custom', $existing));
    }
    Mail::to('approved@example.test')->send($mail);
    $messages = Mail::mailer('array')->getSymfonyTransport()->messages();
    expect($messages)->toHaveCount(1);
    $message = $messages->last()->getOriginalMessage();
    expect($message->getHeaders()->get('X-Mailin-custom')?->getBodyAsString())
        ->toBe(($existing ? $existing.'|' : '').'cozy_environment:development');
})->with([null, 'fixture:safe']);

it('ignores unmarked or foreign email events before retaining any metadata', function (mixed $marker, string $email) {
    $owner = User::factory()->create(['email' => 'approved@example.test']);
    $this->withToken(str_repeat('d', 32))->postJson('/webhooks/brevo', [
        'event' => 'hardBounce', 'email' => $email, 'message-id' => 'other-environment-message',
        'date' => now()->toIso8601String(), 'X-Mailin-custom' => $marker,
    ])->assertOk();
    expect((bool) $owner->fresh()->email_invalid)->toBeFalse();
    $this->assertDatabaseCount('bounces', 0);
    $this->assertDatabaseCount('email_delivery_logs', 0);
})->with([
    'no marker even for the owned inbox' => [null, 'approved@example.test'],
    'production marker' => ['cozy_environment:production', 'approved@example.test'],
    'lookalike marker' => ['cozy_environment:development-other', 'approved@example.test'],
    'malformed marker' => [['cozy_environment:development'], 'approved@example.test'],
    'unapproved inbox' => ['cozy_environment:development', 'someone-else@example.test'],
]);

it('accepts only authenticated development events for the approved mailbox', function () {
    $owner = User::factory()->create(['email' => 'approved@example.test']);
    $payload = ['event' => 'hardBounce', 'email' => strtoupper($owner->email),
        'message-id' => 'own-development-message', 'date' => now()->toIso8601String(),
        'X-Mailin-custom' => 'other:safe|cozy_environment:development'];
    $this->postJson('/webhooks/brevo', $payload)->assertUnauthorized();
    $this->assertDatabaseCount('bounces', 0);
    $this->withToken(str_repeat('d', 32))->postJson('/webhooks/brevo', $payload)->assertOk();
    expect((bool) $owner->fresh()->email_invalid)->toBeTrue();
    $this->assertDatabaseCount('bounces', 1);
    $this->assertDatabaseHas('email_delivery_logs', ['status' => 'bounced']);
});

it('fails closed when the development webhook has inconsistent environment settings', function () {
    config(['app.url' => 'https://wrong-environment.example.test']);
    $this->withToken(str_repeat('d', 32))->postJson('/webhooks/brevo', [
        'event' => 'hardBounce', 'email' => 'approved@example.test', 'message-id' => 'fixture',
        'date' => now()->toIso8601String(), 'X-Mailin-custom' => 'cozy_environment:development',
    ])->assertStatus(503);
    $this->assertDatabaseCount('email_delivery_logs', 0);
});
