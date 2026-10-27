<?php
namespace App\Mail;
use Illuminate\Mail\Mailable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
class VerifyEmailMail extends Mailable implements ShouldQueue, \Illuminate\Contracts\Queue\ShouldBeEncrypted
{
    use \App\Mail\Concerns\TracksDelivery;
    use Queueable;
    public ?int $userId = null;
    public ?string $recipientEmail = null;
    public function __construct(public string $code, ?int $userId = null, ?string $recipientEmail = null)
    {
        $this->initializeDelivery(); $this->onQueue('email');
        $this->userId = $userId; $this->recipientEmail = $recipientEmail;
    }
    public function shouldSend(): bool
    {
        // Previews may render without an owner; legacy unbound queued codes are discarded.
        if (!$this->userId || !$this->recipientEmail) return false;
        $owner = \App\Models\User::find($this->userId);
        $record = \Illuminate\Support\Facades\DB::table('email_verification_codes')->where('user_id', $this->userId)->first();
        return $owner && $owner->is_active && !$owner->email_invalid && !$owner->email_verified_at
            && $owner->email === $this->recipientEmail && $record && $record->failed_attempts < 5
            && hash_equals(hash_hmac('sha256', $owner->email, config('app.key')), (string) $record->email_key)
            && now()->lt($record->expires_at) && \Illuminate\Support\Facades\Hash::check($this->code, $record->code_hash);
    }
    public function build() { return $this->subject('Verify your Cozy Health email')->view('emails.auth.verify-email')->text('emails.auth.verify-email-text'); }
}
