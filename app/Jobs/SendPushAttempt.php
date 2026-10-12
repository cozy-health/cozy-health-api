<?php

namespace App\Jobs;

use App\Models\{AppNotification, PushDevice, PushReminder, User, UserProfile};
use App\Services\Push\{PushSender, ReminderScheduler, ReminderSettings};
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class SendPushAttempt implements ShouldQueue
{
    use Queueable;
    public $tries = 1;
    public $timeout = 45;

    public function __construct(public int $attemptId) {}

    public function handle(PushSender $sender, ReminderScheduler $scheduler): void
    {
        $work = DB::transaction(function () use ($scheduler) {
            $attempt = DB::table('push_attempts')->where('id', $this->attemptId)->lockForUpdate()->first();
            if (! $attempt || ! in_array($attempt->status, ['queued', 'transient']) || ($attempt->retry_at && CarbonImmutable::parse($attempt->retry_at)->isFuture())) return null;
            $reminder = PushReminder::find($attempt->reminder_id);
            $user = User::find($reminder?->user_id);
            if ($user) $user = User::whereKey($user->id)->lockForUpdate()->first();
            $profile = UserProfile::withoutGlobalScope('user')->where('user_id', $user?->id)->first();
            $device = $scheduler->devices($user?->id ?? 0)->whereKey($attempt->device_id)->first();
            $now = CarbonImmutable::now($user?->timezone ?? 'UTC');
            $status = null;
            if (! $scheduler->enabled() || ! $reminder || ! $user || ! $profile || ! $device
                || ! $scheduler->eligible($user, $profile, $reminder->category, $now)) $status = 'cancelled';
            elseif ($reminder->expires_at->lte($now) || $reminder->dispatch_date !== $now->toDateString()) $status = 'expired';
            elseif ($scheduler->afterQuiet($now, ReminderSettings::for($profile))->gt($now)) $status = 'expired';
            elseif (! $reminder->send_started_at && $reminder->dispatched_at->lt($now->subMinutes(15))) $status = 'expired';
            if (! $status) {
                $lastSend = PushReminder::where('user_id', $user->id)->where('id', '!=', $reminder->id)->max('send_started_at');
                if ($lastSend && CarbonImmutable::parse($lastSend, 'UTC')->addHours(2)->gt($now)) $status = 'expired';
            }
            if ($status) { DB::table('push_attempts')->where('id', $attempt->id)->update(['status' => $status, 'updated_at' => now()]); return null; }
            DB::table('push_attempts')->where('id', $attempt->id)->update(['status' => 'sending', 'attempts' => $attempt->attempts + 1, 'updated_at' => now()]);
            if (! $reminder->send_started_at) $reminder->update(['send_started_at' => $now->utc()]);
            return [$attempt, $device, AppNotification::withoutGlobalScope('user')->find($reminder->id)];
        });
        if (! $work) return;
        [$attempt, $device, $notification] = $work;
        $status = $notification ? $sender->send($device, $notification) : 'failed';
        if ($status === 'invalid') $device->delete();
        if ($status === 'transient' && $attempt->attempts >= 2) $status = 'failed';
        DB::table('push_attempts')->where('id', $attempt->id)->update(['status' => $status,
            'retry_at' => $status === 'transient' ? now()->addMinutes(2 ** ($attempt->attempts + 1)) : null, 'updated_at' => now()]);
        $states = DB::table('push_attempts')->where('reminder_id', $attempt->reminder_id)->pluck('status');
        if (! $states->intersect(['queued', 'sending', 'transient'])->count()) {
            PushReminder::whereKey($attempt->reminder_id)->update(['status' => $states->contains('accepted') ? 'accepted' : ($states->contains('unknown') ? 'unknown' : 'failed')]);
        }
    }
}
