<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class WelcomeMail extends Mailable implements \Illuminate\Contracts\Queue\ShouldQueue, \Illuminate\Contracts\Queue\ShouldBeEncrypted
{
    use \App\Mail\Concerns\TracksDelivery;
    use Queueable, SerializesModels;

    public $user;

    /**
     * Create a new message instance.
     */
    public function __construct(User $user)
    {
        $this->initializeDelivery(); $this->onQueue('email');
        $this->user = $user;
    }

    /**
     * Build the message.
     */
    public function build()
    {
        return $this->subject('Welcome to Cozy Health, ' . $this->user->name)
                    ->replyTo('team@cozyhealth.org', 'Cozy Health Team')
                    ->view('emails.auth.welcome')->text('emails.auth.welcome-text');
    }
}
