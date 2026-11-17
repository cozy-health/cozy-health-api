<?php

use App\Mail\{PasswordResetRequestMail, ProviderPasswordResetMail, SignInChallengeMail, VerifyEmailMail, ProviderInvitationMail};
use App\Models\{User, Provider, ProviderClientLink};
use App\Services\SignInSecurity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Hash, Mail, Queue};
use Illuminate\Support\Str;
use Illuminate\Mail\SendQueuedMailable;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['mail.default' => 'array', 'mail.mailers.array' => ['transport' => 'array'],
        'queue.connections.database.after_commit' => false]);
});

function lifecycleMail(string $kind, string $state): array
{
    $provider = str_contains($kind, 'provider');
    $owner = $provider ? Provider::create(['name' => 'Queue fixture', 'email' => 'provider@example.test'])
        : User::factory()->create(['email_verified_at' => null]);
    $code = '543210';
    $hash = Hash::make($state === 'replaced' ? '654321' : $code);
    $expires = $state === 'expired' ? now() : now()->addMinutes(10);
    if (str_starts_with($kind, 'reset')) {
        $table = $provider ? 'provider_password_reset_tokens' : 'password_reset_tokens';
        if ($state !== 'used') DB::table($table)->insert(['email' => $owner->email, 'token' => $hash, 'created_at' => now(), 'expires_at' => $expires]);
        $mail = $provider ? new ProviderPasswordResetMail($owner, $code) : new PasswordResetRequestMail($owner, $code);
    } elseif (str_starts_with($kind, 'signin')) {
        $id = (string) Str::uuid();
        DB::table('signin_challenges')->insert(['id' => $id, 'owner_type' => $owner->getMorphClass(), 'owner_id' => $owner->id,
            'token_hash' => hash('sha256', $id), 'email_hash' => app(SignInSecurity::class)->emailHash($owner),
            'method' => 'email', 'device_name' => 'Test queue', 'code_hash' => $hash, 'expires_at' => $expires,
            'consumed_at' => $state === 'used' ? now() : null, 'created_at' => now(), 'updated_at' => now()]);
        $mail = new SignInChallengeMail($id, $code, $owner->email);
    } elseif ($kind === 'verification') {
        if ($state !== 'used') DB::table('email_verification_codes')->insert(['user_id' => $owner->id, 'code_hash' => $hash,
            'email_key' => hash_hmac('sha256', $owner->email, config('app.key')), 'expires_at' => $expires]);
        $mail = new VerifyEmailMail($code, $owner->id, $owner->email);
    } else {
        $link = ProviderClientLink::create(['user_id' => $owner->id, 'provider_email' => 'invite@example.test',
            'verification_actor' => 'provider', 'verification_code' => $hash, 'verification_expires_at' => $expires,
            'verified_at' => $state === 'used' ? now() : null, 'invitation_sent_at' => now()]);
        $mail = new ProviderInvitationMail($link->id, $code);
        return [$mail, $link->provider_email];
    }
    return [$mail, $owner->email];
}

it('runs code validity checks in the actual encrypted database-queue job and records the outcome', function (string $kind, string $state) {
    [$mail, $email] = lifecycleMail($kind, $state);
    $mail->to($email);
    $job = new SendQueuedMailable($mail);
    $job->afterCommit = false; // Isolate the real worker path inside the test transaction.
    Queue::connection('database')->push($job, '', 'email');
    $this->assertDatabaseHas('email_delivery_logs', ['id' => $mail->deliveryId, 'status' => 'queued']);
    $queued = Queue::connection('database')->pop('email');
    expect($queued)->not->toBeNull();
    $queued->fire();
    $queued->delete();
     $messages = Mail::mailer('array')->getSymfonyTransport()->messages();
    expect($messages)->toHaveCount($state === 'current' ? 1 : 0);
    if ($state === 'current') {
        $sent = $messages->last()->getOriginalMessage();
        expect($sent->getHtmlBody())->toContain('543210');
        expect($sent->getTextBody())->toContain('543210');
    }
    $this->assertDatabaseHas('email_delivery_logs', ['id' => $mail->deliveryId, 'status' => $state === 'current' ? 'sent' : 'suppressed']);
    expect(json_encode(DB::table('email_delivery_logs')->get()))->not->toContain('543210', $email);
    $this->assertDatabaseCount('jobs', 0);
})->with(['reset-user', 'reset-provider', 'signin-user', 'signin-provider', 'verification', 'invitation'])
    ->with(['current', 'expired', 'replaced', 'used']);
