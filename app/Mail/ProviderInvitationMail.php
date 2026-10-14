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

    public function __construct(public int $linkId, public string $code)
    {
        $this->initializeDelivery();
        $this->onQueue('email');
    }

    public function shouldSend(): bool
    {
        $link = ProviderClientLink::find($this->linkId);
        return $link && $link->verification_actor === 'provider' && ! $link->revoked_at && ! $link->verified_at
            && $link->verification_expires_at?->isFuture() && $link->verification_code
            && Hash::check($this->code, $link->verification_code);
    }

    public function build()
    {
        return $this->subject('A patient invited you to connect on Cozy')
            ->view('emails.provider-invitation', ['portalUrl' => config('services.provider.portal_url')])
            ->text('emails.provider-invitation-text', ['portalUrl' => config('services.provider.portal_url')]);
    }
}
