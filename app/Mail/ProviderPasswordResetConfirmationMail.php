<?php

namespace App\Mail;

use App\Models\Provider;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ProviderPasswordResetConfirmationMail extends Mailable implements ShouldQueue
{
    use \App\Mail\Concerns\TracksDelivery;
    use Queueable, SerializesModels;

    public function __construct(public Provider $user)
    {
        $this->initializeDelivery(); $this->onQueue('email');
    }

    public function build()
    {
        return $this->subject('Your Cozy Health provider password has been reset')
            ->replyTo('team@cozyhealth.org', 'Cozy Health Support')
            ->view('emails.auth.password-reset-confirmation')
            ->text('emails.auth.password-reset-confirmation-text');
    }
}
