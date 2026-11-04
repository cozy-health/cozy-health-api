<?php

namespace App\Mail;

use App\Models\SupportMessage;
use App\Models\SupportReport;
use App\Models\User;

class SupportReplyMail extends BrandedNoticeMail
{
    public function __construct(public string $messageId)
    {
        parent::__construct('Cozy Health support update', 'You have a support update',
            ['Open Help & Support in Cozy Health to read and reply. This email contains no conversation details.'],
            [['label' => 'Open support', 'url' => rtrim(config('app.url'), '/').'/support-inbox']]);
        $this->onQueue('email');
    }

    public function shouldSend(): bool
    {
        $message = SupportMessage::find($this->messageId);
        $report = $message ? SupportReport::find($message->support_report_id) : null;
        $user = $report ? User::find($report->user_id) : null;

        return $message?->author === 'support' && $message->delivery_id === $this->deliveryId
            && $user?->is_active && $user->email_verified_at && ! $user->email_invalid && $this->hasTo($user->email);
    }
}
