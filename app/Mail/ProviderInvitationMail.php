<?php

namespace App\Mail;

use App\Models\ProviderClientLink;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Hash;

class ProviderInvitationMail extends Mailable implements ShouldQueue, ShouldBeEncrypted
{
    use Concerns\TracksDelivery, Queueable, SerializesModels;

    protected ?array $previewData = null;

    public function __construct(public int $linkId, public string $code)
    {
        $this->initializeDelivery();
        $this->onQueue('email');
    }

    public function shouldSend(): bool
    {
        $link = ProviderClientLink::with(['user', 'provider'])->find($this->linkId);
        return $link && $link->user && $link->user->is_active
            && (! $link->provider || $link->provider->is_active)
            && $link->verification_actor === 'provider' && ! $link->revoked_at && ! $link->verified_at
            && $link->verification_expires_at?->isFuture() && $link->verification_code
            && Hash::check($this->code, $link->verification_code);
    }

    public function build()
    {
        $link = $this->previewData === null ? ProviderClientLink::with(['user', 'provider'])->find($this->linkId) : null;
        $details = $this->previewData ?? [
            'patientName' => trim($link?->user?->name ?? '') ?: 'A patient',
            'providerName' => trim($link?->provider?->name ?? '') ?: null,
            'recipientEmail' => $link?->provider_email,
            'expiresAt' => $link?->verification_expires_at ? $link->verification_expires_at->copy()->utc()->format('j M Y, H:i').' UTC' : null,
            'previewLogo' => null,
        ];
        $details['portalUrl'] = config('services.provider.portal_url');
        return $this->subject('A patient invited you to connect on Cozy')
            ->replyTo('team@cozyhealth.org', 'Cozy Health Support')
            ->view('emails.provider-invitation', $details)
            ->text('emails.provider-invitation-text', $details);
    }

    /** Admin previews use only synthetic names and codes, never a patient's link. */
    public static function example(bool $registered = true): self
    {
        $mail = new self(0, '000000');
        $mail->previewData = [
            'patientName' => 'Preview Patient', 'providerName' => $registered ? 'Preview Provider' : null,
            'recipientEmail' => 'preview@example.test',
            'expiresAt' => now()->addMinutes(15)->utc()->format('j M Y, H:i').' UTC',
            'previewLogo' => 'data:image/png;base64,'.base64_encode(file_get_contents(public_path('images/cozy-logo.png'))),
        ];
        return $mail;
    }
}
