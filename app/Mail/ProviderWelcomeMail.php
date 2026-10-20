<?php

namespace App\Mail;

use App\Models\Provider;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ProviderWelcomeMail extends Mailable implements ShouldQueue, ShouldBeEncrypted
{
    use Concerns\TracksDelivery, Queueable, SerializesModels;

    public function __construct(public Provider $provider, public bool $preview = false)
    {
        $this->initializeDelivery();
        $this->onQueue('email');
        $this->afterCommit();
    }

    public function build()
    {
        $details = [
            'providerName' => $this->provider->name,
            'portalUrl' => config('services.provider.portal_url'),
            'previewLogo' => $this->preview ? 'data:image/png;base64,'.base64_encode(file_get_contents(public_path('images/cozy-logo.png'))) : null,
        ];
        return $this->subject('Welcome to Cozy Health')
            ->replyTo('team@cozyhealth.org', 'Cozy Health Support')
            ->view('emails.provider-welcome', $details)
            ->text('emails.provider-welcome-text', $details);
    }
}
