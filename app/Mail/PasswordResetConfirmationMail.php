<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class PasswordResetConfirmationMail extends Mailable implements \Illuminate\Contracts\Queue\ShouldQueue
{
    use Queueable, SerializesModels;

    public $user;

    public function __construct(User $user)
    {
        $this->onQueue('email');
        $this->user = $user;
    }

    public function build()
    {
        return $this->subject('Your password has been reset')
                    ->replyTo('team@cozyhealth.org', 'Cozy Health Support')
                    ->view('emails.password-reset-confirmation');
    }
}
