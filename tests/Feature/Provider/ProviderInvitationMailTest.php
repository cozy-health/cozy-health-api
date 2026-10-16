<?php

namespace Tests\Feature\Provider;

use App\Mail\ProviderInvitationMail;
use App\Models\Provider;
use App\Models\ProviderClientLink;
use App\Models\User;
use App\Services\AdminEmailRegistry;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

class ProviderInvitationMailTest extends ProviderDataTestCase
{
    private function invitation(?Provider $provider = null): ProviderClientLink
    {
        return ProviderClientLink::create([
            'provider_id' => $provider?->id, 'provider_email' => $provider?->email ?? 'future@example.test',
            'user_id' => User::factory()->create(['name' => 'Amara Okafor'])->id,
            'verification_actor' => 'provider', 'verification_code' => Hash::make('123456'),
            'verification_expires_at' => now()->addMinutes(15), 'invitation_sent_at' => now(),
            'consent_moods' => true,
        ]);
    }

    public function test_html_and_plain_text_include_current_names_code_expiry_and_instructions(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 14)->setTime(10, 0));
        config(['services.provider.portal_url' => 'https://portal.example.test/provider']);
        $provider = $this->provider(['name' => 'Dr. Avery']);
        $link = $this->invitation($provider);
        $mail = new ProviderInvitationMail($link->id, '123456');
        $link->user->update(['name' => 'Amara Updated']);
        $mail->assertHasSubject('A patient invited you to connect on Cozy');
        foreach (['Amara Updated', 'Dr. Avery', '123456', '14 Oct 2026, 10:15 UTC', $provider->email, 'Accept patient invitation', 'Open provider dashboard', 'https://portal.example.test/provider'] as $text) {
            $mail->assertSeeInHtml($text);
            $mail->assertSeeInText($text);
        }
        $mail->assertDontSeeInHtml('Amara Okafor');
        $this->assertStringContainsString('data:image/png;base64,', $mail->render());
        $this->assertInstanceOf(ShouldQueue::class, $mail);
        $this->assertInstanceOf(ShouldBeEncrypted::class, $mail);
        $this->assertTrue($mail->shouldSend());
    }

    public function test_unicode_and_html_characters_are_escaped_only_in_html(): void
    {
        $provider = $this->provider(['name' => 'Dr. Zoë & Team']);
        $link = $this->invitation($provider);
        $name = 'Chloë <img src=x onerror=alert(1)> & Amina';
        $link->user->update(['name' => $name]);
        $mail = new ProviderInvitationMail($link->id, '123456');
        $mail->assertSeeInHtml($name);
        $this->assertStringNotContainsString('<img src=x onerror=alert(1)>', $mail->render());
        $mail->assertSeeInText($name);
        $mail->assertSeeInText('Dr. Zoë & Team');
    }

    public function test_unregistered_provider_uses_generic_greeting_and_registration_instructions(): void
    {
        $link = $this->invitation();
        $mail = new ProviderInvitationMail($link->id, '123456');
        $mail->withSymfonyMessage(function ($message) {
            $this->assertStringContainsString('cid:', $message->getHtmlBody());
            $this->assertCount(1, $message->getAttachments());
            $this->assertSame('inline', $message->getAttachments()[0]->getDisposition());
        });
        $mail->assertSeeInHtml('Hello,');
        $mail->assertSeeInHtml('Amara Okafor');
        $mail->assertSeeInText('create your provider account');
        $mail->assertSeeInText('future@example.test');
        $this->assertTrue($mail->shouldSend());
    }

    public function test_stale_expired_revoked_consumed_and_deleted_patient_invitations_do_not_send(): void
    {
        foreach ([['revoked_at' => now()], ['verified_at' => now()], ['verification_expires_at' => now()->subSecond()], ['verification_code' => Hash::make('654321')]] as $changes) {
            $link = $this->invitation();
            $mail = new ProviderInvitationMail($link->id, '123456');
            $link->update($changes);
            $this->assertFalse($mail->shouldSend());
        }
        $provider = $this->provider();
        $link = $this->invitation($provider);
        $provider->update(['is_active' => false]);
        $this->assertFalse((new ProviderInvitationMail($link->id, '123456'))->shouldSend());
        $link = $this->invitation();
        $link->user->delete();
        $this->assertFalse((new ProviderInvitationMail($link->id, '123456'))->shouldSend());
    }

    public function test_admin_preview_and_delivery_tracking_never_include_patient_identity_or_code(): void
    {
        $link = $this->invitation();
        $preview = AdminEmailRegistry::preview('provider-invitation');
        $this->assertStringContainsString('Preview Patient', $preview->render());
        $this->assertStringContainsString('Preview Provider', $preview->render());
        $this->assertStringContainsString('data:image/png;base64,', $preview->render());
        $this->assertStringNotContainsString('Amara Okafor', $preview->render());
        $preview->assertSeeInText('000000');
        $this->assertFalse($preview->shouldSend());
        config(['mail.default' => 'array', 'mail.mailers.array' => ['transport' => 'array']]);
        $mail = new ProviderInvitationMail($link->id, '123456');
        Mail::to($link->provider_email)->send($mail);
        $this->assertDatabaseHas('email_delivery_logs', ['id' => $mail->deliveryId, 'event' => 'provider-invitation', 'status' => 'sent']);
        $logs = json_encode(DB::table('email_delivery_logs')->get());
        foreach (['Amara Okafor', 'future@example.test', '123456'] as $private) $this->assertStringNotContainsString($private, $logs);
    }
}
