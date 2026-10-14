<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;

class SendTestEmail implements ShouldQueue
{
    use Queueable;
    public string $deliveryId;

    public function __construct(public string $recipient)
    {
        $this->deliveryId = (string) \Illuminate\Support\Str::uuid();
        $this->onQueue('email');
    }

    public function handle(): void
    {
        if (!isset($this->deliveryId)) $this->deliveryId = (string) \Illuminate\Support\Str::uuid();
        Mail::raw('The Cozy Health email queue is working.', function ($mail) {
            $mail->to($this->recipient)->subject('Cozy Health queue check');
            $mail->getSymfonyMessage()->getHeaders()->addTextHeader('X-Cozy-Delivery', $this->deliveryId);
        });
    }
}
