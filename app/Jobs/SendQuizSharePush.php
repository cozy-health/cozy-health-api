<?php
namespace App\Jobs;
use App\Models\{AppNotification, User, UserProfile};
use App\Services\Push\{PushSender, ReminderScheduler, ReminderSettings};
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use App\Services\Push\ShareDeliveryWindow;

/** Transactional share notices are not reminders and never carry result content. */
class SendQuizSharePush implements ShouldQueue
{
    use Queueable;
    public $tries = 1;
    public $timeout = 45;
    public function __construct(public string $notificationId) {}
    public function handle(PushSender $sender, ReminderScheduler $scheduler): void
    {
        $notification = AppNotification::withoutGlobalScope('user')->find($this->notificationId);
        if (! $notification || $notification->created_at->lt(now()->subMinutes(15))) return;
        $user = User::find($notification->user_id);
        $profile = UserProfile::withoutGlobalScope('user')->where('user_id', $user?->id)->first();
        if (! $scheduler->enabled() || ! $user?->is_active || $user->is_demo || ! $profile?->push_consent || ! $profile->notifications_master) return;
        $time = CarbonImmutable::now($user->timezone);
        if ($scheduler->afterQuiet($time, ReminderSettings::for($profile))->gt($time)) return;
        $grant = DB::table('quiz_friend_shares')->where('id', $notification->data['share_id'] ?? '')
            ->where('recipient_id', $user->id)->whereNull('revoked_at')->whereNull('dismissed_at')->first();
        if (! $grant) return;
        foreach ($scheduler->devices($user->id)->get() as $device) {
            $reserved = DB::transaction(function () use ($device, $scheduler, $notification) {
                $current = User::whereKey($device->user_id)->lockForUpdate()->firstOrFail();
                $profile = UserProfile::withoutGlobalScope('user')->where('user_id', $current->id)->lockForUpdate()->first();
                if (! $scheduler->enabled() || ! $current->is_active || $current->is_demo || ! $profile?->push_consent || ! $profile->notifications_master) return false;
                if (! $scheduler->devices($current->id)->whereKey($device->id)->exists()) return false;
                $time = CarbonImmutable::now($current->timezone);
                if ($scheduler->afterQuiet($time, ReminderSettings::for($profile))->gt($time)) return false;
                if (! DB::table('quiz_friend_shares')->where('id', $notification->data['share_id'] ?? '')->where('recipient_id', $current->id)->whereNull('revoked_at')->whereNull('dismissed_at')->exists()) return false;
                $last = \App\Models\PushReminder::where('user_id', $current->id)->max('send_started_at');
                $lastShare = ShareDeliveryWindow::latest($current->id, $this->notificationId);
                if ($lastShare && (! $last || $lastShare > $last)) $last = $lastShare;
                if ($last && CarbonImmutable::parse($last, 'UTC')->addHours(2)->gt($time)) return false;
                $start = $time->startOfDay()->utc(); $end = $time->addDay()->startOfDay()->utc();
                $count = \App\Models\PushReminder::where('user_id', $current->id)->where('dispatched_at', '>=', $start)->where('dispatched_at', '<', $end)->count();
                if ($count + ShareDeliveryWindow::count($current->id, $start, $end, $this->notificationId) >= 3) return false;
                if (DB::table('quiz_push_attempts')->where('notification_id', $this->notificationId)->where('device_id', $device->id)->exists()) return false;
                DB::table('quiz_push_attempts')->insert(['notification_id' => $this->notificationId, 'device_id' => $device->id,
                    'status' => 'sending', 'created_at' => now(), 'updated_at' => now()]);
                return true;
            });
            if (! $reserved) continue;
            try { $status = $sender->send($device, $notification); }
            catch (\Throwable) { $status = 'unknown'; }
            DB::table('quiz_push_attempts')->where('notification_id', $this->notificationId)->where('device_id', $device->id)->update(['status' => $status, 'updated_at' => now()]);
            if ($status === 'invalid') $device->delete();
            // Ambiguous send outcomes are never replayed. The inbox remains authoritative.
        }
    }
}
