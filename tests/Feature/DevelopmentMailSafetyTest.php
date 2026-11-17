<?php

namespace Tests\Feature;

use App\Mail\VerifyEmailMail;
use App\Services\{AdminEmailRegistry, CommunityAvailability, DevelopmentMailSafety};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Support\Facades\{Crypt, DB, Log, Mail, Queue};
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class DevelopmentMailSafetyTest extends TestCase
{
    use RefreshDatabase;
    protected function setUp(): void
    {
        parent::setUp();
        config(['development_mail.project_id' => CommunityAvailability::DEVELOPMENT_PROJECT,
            'development_mail.environment_id' => CommunityAvailability::DEVELOPMENT_ENVIRONMENT,
            'app.url' => 'https://api-dev.cozyhealth.org',
            'development_mail.allowed_recipients' => ['approved@example.test'],
            'mail.default' => 'array', 'mail.mailers.array' => ['transport' => 'array']]);
    }

    public function test_only_explicit_recipients_can_receive_development_mail_including_cc_and_bcc(): void
    {
        $service = app(DevelopmentMailSafety::class);
        $message = (new Email)->from('noreply@example.test')->to('APPROVED@example.test')->text('Synthetic check');
        $service->assertRecipients($message);
        foreach (['cc', 'bcc', 'to'] as $field) {
            $candidate = clone $message; $candidate->$field('unapproved@example.test');
            try { $service->assertRecipients($candidate); $this->fail('Unapproved recipient was accepted.'); }
            catch (\RuntimeException $error) { $this->assertSame('Development email recipient is not approved for testing.', $error->getMessage()); }
        }
    }

    public function test_development_target_with_invalid_url_fails_closed(): void
    {
        foreach (['https://api.cozyhealth.org', 'https://user@api-dev.cozyhealth.org', 'http://api-dev.cozyhealth.org'] as $url) {
            config(['app.url' => $url]);
            try { app(DevelopmentMailSafety::class)->assertRecipients((new Email)->to('approved@example.test')); $this->fail('Invalid development identity was accepted.'); }
            catch (\RuntimeException $error) { $this->assertSame('Development email environment identity is invalid.', $error->getMessage()); }
        }
    }

    public function test_log_transport_never_renders_verification_codes_into_logs(): void
    {
        $owner = \App\Models\User::factory()->create(['email' => 'approved@example.test', 'email_verified_at' => null]);
        DB::table('email_verification_codes')->insert(['user_id' => $owner->id, 'code_hash' => \Illuminate\Support\Facades\Hash::make('543210'), 'expires_at' => now()->addMinutes(15), 'email_key' => hash_hmac('sha256', $owner->email, config('app.key'))]);
        config(['mail.default' => 'log']); Log::spy(); Log::shouldReceive('channel')->andReturnSelf();
        try {
            Mail::to('approved@example.test')->sendNow(new VerifyEmailMail('543210', $owner->id, $owner->email));
            $this->fail('A code was written using log transport.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('Log transport cannot deliver', $error->getMessage());
            $this->assertStringNotContainsString('543210', $error->getMessage());
        }
        Log::shouldNotHaveReceived('debug');
    }

    public function test_mail_queue_payload_is_encrypted_and_retries_are_bounded(): void
    {
        foreach (array_keys(AdminEmailRegistry::EVENTS) as $kind) {
            $job = new SendQueuedMailable(AdminEmailRegistry::preview($kind));
            $this->assertTrue($job->shouldBeEncrypted, $kind);
            $this->assertSame(3, $job->tries);
            $this->assertSame(30, $job->timeout);
        }
        $job = new SendQueuedMailable(new VerifyEmailMail('543210'));
        config(['queue.connections.database.after_commit' => false]);
        $id = Queue::connection('database')->push($job, '', 'email');
        $payload = json_decode(DB::table('jobs')->where('id', $id)->value('payload'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('543210', $payload['data']['command']);
        $this->assertStringContainsString('543210', Crypt::decrypt($payload['data']['command']));
    }

    public function test_diagnostic_cannot_send_to_unconfigured_or_unapproved_recipients(): void
    {
        config(['development_mail.test_recipient' => 'unapproved@example.test']);
        $this->artisan('mail:development-check', ['--send-test' => true])->assertFailed();
        $this->assertDatabaseCount('jobs', 0);
        config(['development_mail.environment_id' => 'unrelated-environment']);
        $this->artisan('mail:development-check', ['--smtp-check' => true])->assertFailed();
    }
}
