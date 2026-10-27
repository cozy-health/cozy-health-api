<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class PasswordResetRequestMail extends Mailable implements \Illuminate\Contracts\Queue\ShouldQueue, \Illuminate\Contracts\Queue\ShouldBeEncrypted
{
    use \App\Mail\Concerns\TracksDelivery;
    use Queueable, SerializesModels;

    public $user;
    public $token;

    public function __construct(User $user, $token)
    {
        $this->initializeDelivery(); $this->onQueue('email');
        $this->user = $user;
        $this->token = $token;
    }

    public function shouldSend(): bool
    {
        $owner = User::find($this->user->id);
        $record = \Illuminate\Support\Facades\DB::table('password_reset_tokens')->where('email', $owner?->email)->first();
        return $owner && $owner->is_active && ! $owner->email_invalid
            && $record && $record->failed_attempts < 5 && now()->lt($record->expires_at)
            && \Illuminate\Support\Facades\Hash::check($this->token, $record->token);
    }

    public function build()
    {
        return $this->subject('Reset your Cozy Health password')
                    ->replyTo('team@cozyhealth.org', 'Cozy Health Support')
                    ->view('emails.auth.password-reset')->text('emails.auth.password-reset-text');
    }
}
