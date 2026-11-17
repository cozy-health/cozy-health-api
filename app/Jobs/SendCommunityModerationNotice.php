<?php

namespace App\Jobs;

use App\Mail\BrandedNoticeMail;
use App\Models\User;
use Illuminate\Contracts\Queue\{ShouldBeEncrypted, ShouldQueue};
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\{Crypt, DB, Mail};

class SendCommunityModerationNotice implements ShouldQueue, ShouldBeEncrypted
{
    use Queueable;
    public int $tries = 3;
    public int $timeout = 30;
    public function __construct(public string $noticeId) { $this->onQueue('email'); }
    public function backoff(): array { return [60, 300, 900]; }
    public function handle(): void
    {
        $snapshot = DB::table('community_moderation_notices')->where('id', $this->noticeId)->first();
        if (!$snapshot) return;
        DB::transaction(function () use ($snapshot) {
            $user = User::whereKey($snapshot->user_id)->lockForUpdate()->first();
            $row = DB::table('community_moderation_notices')->where('id', $this->noticeId)->lockForUpdate()->first();
            if (!$row || !in_array($row->status, ['pending', 'failed'], true) || $row->attempts >= 3 || ($row->retry_at && now()->lt($row->retry_at))) return;
            if (!$user || !$user->is_active || !$user->email_verified_at || $user->email_invalid || !hash_equals($row->email_key, hash('sha256', strtolower(trim($user->email))))) {
                DB::table('community_moderation_notices')->where('id', $row->id)->update(['status' => 'obsolete', 'updated_at' => now()]);
                return;
            }
            [$title, $body] = json_decode(Crypt::decryptString($row->payload), true, 512, JSON_THROW_ON_ERROR);
            $mail = new BrandedNoticeMail($title, $title, [$body, 'Open Community moderation in Cozy Health to review the decision or appeal status.']);
            $mail->deliveryId = $row->id;
            try {
                Mail::to($user->email)->send($mail);
                DB::table('community_moderation_notices')->where('id', $row->id)->update(['status' => 'sent', 'attempts' => $row->attempts + 1, 'updated_at' => now()]);
            } catch (\Throwable) {
                DB::table('community_moderation_notices')->where('id', $row->id)->update(['status' => 'failed', 'attempts' => $row->attempts + 1, 'retry_at' => now()->addMinutes(5), 'updated_at' => now()]);
            }
        }, 3);
    }
}
