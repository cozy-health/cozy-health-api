<?php
namespace App\Mail;
use Illuminate\Mail\Mailable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
class VerifyEmailMail extends Mailable implements ShouldQueue
{
    use \App\Mail\Concerns\TracksDelivery;
    use Queueable;
    public function __construct(public string $code) { $this->initializeDelivery(); $this->onQueue('email'); }
    public function build() { return $this->subject('Verify your Cozy Health email')->view('emails.auth.verify-email')->text('emails.auth.verify-email-text'); }
}
