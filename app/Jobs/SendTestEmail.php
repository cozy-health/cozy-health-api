<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;

class SendTestEmail implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $recipient)
    {
        $this->onQueue('email');
    }

    public function handle(): void
    {
        Mail::raw('The Cozy Health email queue is working.', fn ($mail) => $mail->to($this->recipient)->subject('Cozy Health queue check'));
    }
}
