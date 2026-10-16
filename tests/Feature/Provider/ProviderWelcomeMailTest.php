<?php

namespace Tests\Feature\Provider;

use App\Mail\ProviderWelcomeMail;
use App\Services\AdminEmailRegistry;
use Illuminate\Support\Facades\Mail;

class ProviderWelcomeMailTest extends ProviderDataTestCase
{
    private function registration(): array
    {
        return ['name' => 'Dr. Avery', 'email' => 'avery@example.test',
            'password' => 'UniqueProvider918!', 'password_confirmation' => 'UniqueProvider918!'];
    }

    public function test_registration_queues_one_welcome_without_patients(): void
    {
        Mail::fake();
        $this->postJson('/api/v1/provider/auth/register', $this->registration())
            ->assertCreated()->assertJsonStructure(['data' => ['provider', 'token']]);
        Mail::assertQueued(ProviderWelcomeMail::class, fn ($mail) => $mail->hasTo('avery@example.test') && $mail->queue === 'email');
        Mail::assertQueuedCount(1);
        $this->assertDatabaseCount('provider_client_links', 0);
    }

    public function test_invalid_and_duplicate_registration_do_not_queue_another_email(): void
    {
        Mail::fake();
        $this->postJson('/api/v1/provider/auth/register', ['email' => 'invalid'])->assertUnprocessable();
        Mail::assertNothingQueued();
        $this->postJson('/api/v1/provider/auth/register', $this->registration())->assertCreated();
        $this->postJson('/api/v1/provider/auth/register', $this->registration())->assertUnprocessable();
        Mail::assertQueuedCount(1);
    }

    public function test_queue_failure_does_not_prevent_registration(): void
    {
        Mail::shouldReceive('to')->once()->with('avery@example.test')->andThrow(new \RuntimeException('Queue unavailable'));
        $this->postJson('/api/v1/provider/auth/register', $this->registration())
            ->assertCreated()->assertJsonStructure(['data' => ['token']]);
        $this->assertDatabaseHas('providers', ['email' => 'avery@example.test']);
    }

    public function test_content_and_tracking_use_the_provider_email_template(): void
    {
        config(['services.provider.portal_url' => 'https://portal.example.test/provider']);
        $mail = new ProviderWelcomeMail($this->provider(['name' => 'Dr. Avery & <Team>']));
        foreach (['Dr. Avery & <Team>', 'Accept patient invitation', 'https://portal.example.test/provider', 'do not have any patients yet'] as $text) {
            $mail->assertSeeInHtml($text);
            $mail->assertSeeInText($text);
        }
        $this->assertStringNotContainsString('<Team>', $mail->render());
        $this->assertInstanceOf(\Illuminate\Contracts\Queue\ShouldBeEncrypted::class, $mail);
        $this->assertSame('provider-welcome', AdminEmailRegistry::event(ProviderWelcomeMail::class));
        $preview = AdminEmailRegistry::preview('provider-welcome');
        $preview->assertSeeInHtml('Preview Provider');
        $this->assertStringContainsString('data:image/png;base64,', $preview->render());
        config(['mail.default' => 'array', 'mail.mailers.array' => ['transport' => 'array']]);
        $mail->withSymfonyMessage(function ($message) {
            $this->assertStringContainsString('cid:', $message->getHtmlBody());
        });
        Mail::to($mail->provider->email)->sendNow($mail);
        $this->assertDatabaseHas('email_delivery_logs', ['id' => $mail->deliveryId, 'event' => 'provider-welcome', 'status' => 'sent']);
    }
}
