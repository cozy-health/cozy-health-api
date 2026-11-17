<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Mail\Mailable;

/** Synchronous so existing confirmation errors and urgent-alert retry rules are preserved. */
class BrandedNoticeMail extends Mailable implements ShouldBeEncrypted
{
    use Concerns\TracksDelivery, Queueable;

    /** @param list<string> $paragraphs @param list<array{label: string, url: string}> $actions */
    public function __construct(public string $subjectLine, public string $heading, public array $paragraphs, public array $actions = [])
    {
        $this->initializeDelivery();
    }

    public function build()
    {
        return $this->subject($this->subjectLine)->replyTo('team@cozyhealth.org', 'Cozy Health Support')
            ->view('emails.notice')->text('emails.notice-text');
    }
}
