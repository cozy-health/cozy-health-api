<?php

namespace App\Mail;

use App\Models\Provider;
use App\Models\User;
use App\Services\SignInSecurity;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class SignInChallengeMail extends BrandedNoticeMail implements ShouldQueue
{
    public function __construct(public string $challengeId, public string $code, public string $recipientEmail)
    {
        parent::__construct('Your Cozy sign-in code', 'Confirm it’s you', [
            'Your verification code is '.$code.'.',
            'This code expires in ten minutes. Never share it with anyone, including support.',
            'If you did not request this check, do not use the code. Contact team@cozyhealth.org if you need help.',
        ]);
        $this->onQueue('email');
        $this->afterCommit();
    }

    public function shouldSend(): bool
    {
        $row = DB::table('signin_challenges')->find($this->challengeId);
        if (! $row || $row->consumed_at || $row->method !== 'email' || $row->failed_attempts >= 5 || now()->gte($row->expires_at)) {
            return false;
        }
        $class = match ($row->owner_type) {
            (new User)->getMorphClass() => User::class,
            (new Provider)->getMorphClass() => Provider::class,
            default => null,
        };
        $owner = $class ? $class::find($row->owner_id) : null;

        return $owner && $owner->is_active && ! $owner->email_invalid && $owner->email === $this->recipientEmail
            && hash_equals($row->email_hash, app(SignInSecurity::class)->emailHash($owner))
            && Hash::check($this->code, $row->code_hash);
    }
}
