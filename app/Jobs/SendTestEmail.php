<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;

class SendTestEmail implements ShouldQueue, \Illuminate\Contracts\Queue\ShouldBeEncrypted
{
    use Queueable;
    public string $deliveryId;
    public int $tries = 3;
    public int $timeout = 30;
    public function backoff(): array { return [30, 120, 300]; }

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
