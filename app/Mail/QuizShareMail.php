<?php
namespace App\Mail;
use Illuminate\Mail\Mailable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
class QuizShareMail extends Mailable implements ShouldQueue
{
    use Queueable;
    // Phase 19 must supply an authenticated, expiring review link after explicit consent.
    public function __construct(public string $reviewUrl) { $this->onQueue('email'); }
    public function build() { return $this->subject('A Cozy Health assessment was shared with you')->view('emails.quiz.share')->text('emails.quiz.share-text'); }
}
