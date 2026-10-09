<?php

namespace App\Mail;

use App\Models\Provider;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ProviderPasswordResetMail extends Mailable implements ShouldQueue, \Illuminate\Contracts\Queue\ShouldBeEncrypted
{
    use Queueable, SerializesModels;

    public function __construct(public Provider $user, public string $token)
    {
        $this->onQueue('email');
    }

    public function build()
    {
        return $this->subject('Reset your Cozy Health provider password')
            ->replyTo('team@cozyhealth.org', 'Cozy Health Support')
            ->view('emails.auth.password-reset')
            ->text('emails.auth.password-reset-text');
    }
}
