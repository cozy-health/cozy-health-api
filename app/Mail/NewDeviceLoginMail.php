<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class NewDeviceLoginMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;
    public function __construct(public string $device, public ?string $ip, public string $loggedInAt) { $this->onQueue('email'); }
    public function build()
    {
        return $this->subject('New device signed in to Cozy Health')->view('emails.auth.new-device')
            ->text('emails.auth.new-device-text');
    }
}
