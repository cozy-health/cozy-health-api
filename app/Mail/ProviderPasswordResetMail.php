<?php

namespace App\Mail;

use App\Models\Provider;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ProviderPasswordResetMail extends Mailable implements ShouldQueue, \Illuminate\Contracts\Queue\ShouldBeEncrypted
{
    use \App\Mail\Concerns\TracksDelivery;
    use Queueable, SerializesModels;

    public function __construct(public Provider $user, public string $token)
    {
        $this->initializeDelivery(); $this->onQueue('email');
    }

    public function shouldSend(): bool
    {
        $owner = Provider::find($this->user->id);
        $record = \Illuminate\Support\Facades\DB::table('provider_password_reset_tokens')->where('email', $owner?->email)->first();
        return $owner && $owner->is_active && ! $owner->email_invalid && $record && $record->failed_attempts < 5 && now()->lt($record->expires_at)
            && \Illuminate\Support\Facades\Hash::check($this->token, $record->token);
    }

    public function build()
    {
        return $this->subject('Reset your Cozy Health provider password')
            ->replyTo('team@cozyhealth.org', 'Cozy Health Support')
            ->view('emails.auth.password-reset')
            ->text('emails.auth.password-reset-text');
    }
}
