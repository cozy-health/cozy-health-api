<?php
namespace App\Mail;
use Illuminate\Mail\Mailable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
class AccountDeletedMail extends Mailable implements ShouldQueue
{
    use Queueable;
    // No User model: the account has already been permanently deleted.
    public function __construct() { $this->onQueue('email'); }
    public function build() { return $this->subject('Your Cozy Health account has been deleted')->view('emails.account.deleted')->text('emails.account.deleted-text'); }
}
