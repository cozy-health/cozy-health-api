<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class PasswordResetRequestMail extends Mailable implements \Illuminate\Contracts\Queue\ShouldQueue, \Illuminate\Contracts\Queue\ShouldBeEncrypted
{
    use Queueable, SerializesModels;

    public $user;
    public $token;

    public function __construct(User $user, $token)
    {
        $this->onQueue('email');
        $this->user = $user;
        $this->token = $token;
    }

    public function build()
    {
        return $this->subject('Reset your Cozy Health password')
                    ->replyTo('team@cozyhealth.org', 'Cozy Health Support')
                    ->view('emails.auth.password-reset')->text('emails.auth.password-reset-text');
    }
}
