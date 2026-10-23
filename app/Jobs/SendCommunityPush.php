<?php
namespace App\Jobs;

use App\Models\{AppNotification, User, UserProfile, PushReminder};
use App\Services\CommunityNotifications;
use App\Services\Push\{PushSender, ReminderScheduler, ReminderSettings, ShareDeliveryWindow};
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class SendCommunityPush implements ShouldQueue
{
    use Queueable;
    public $tries = 5;
    public $timeout = 45;
    public function __construct(public string $notificationId) {}
    public function handle(PushSender $sender, ReminderScheduler $scheduler, CommunityNotifications $community): void
    {
        $notification = AppNotification::withoutGlobalScope('user')->find($this->notificationId);
        if (! $notification || $notification->created_at->lt(now()->subDay()) || ! $scheduler->enabled()
            || ! config('community.release_ready') || ! config('community.guidelines_version')
            || ! \App\Models\FeatureFlag::where('key', 'community')->value('enabled')) return;
        $owner = User::find($notification->user_id);
        if (! $owner) return;
        $retry = false;
        foreach ($scheduler->devices($owner->id)->get() as $device) {
            $reserved = DB::transaction(function () use ($owner, $device, $notification, $scheduler, $community) {
                $user = User::whereKey($owner->id)->lockForUpdate()->first();
                $profile = UserProfile::withoutGlobalScope('user')->where('user_id', $owner->id)->lockForUpdate()->first();
                if (! $user?->is_active || $user->is_demo || ! $profile?->push_consent || ! $profile->notifications_master
                    || ! $community->categoryAllowed($profile, $notification->type)
                    || ! (ReminderSettings::for($profile)['community_push_enabled'] ?? true)
                    || ! app(\App\Services\DocumentConsentService::class)->patientConsentCurrent($user)
                    || ! $community->available($notification) || ! $scheduler->devices($user->id)->whereKey($device->id)->exists()) return false;
                $time = CarbonImmutable::now($user->timezone);
                if ($scheduler->afterQuiet($time, ReminderSettings::for($profile))->gt($time)) return false;
                $attempt = DB::table('community_push_attempts')->where('notification_id', $notification->id)->where('device_id', $device->id)->first();
                if ($attempt && (! in_array($attempt->status, ['queued', 'transient']) || $attempt->attempts >= 3
                    || ($attempt->retry_at && CarbonImmutable::parse($attempt->retry_at)->isFuture()))) return false;
                $last = PushReminder::where('user_id', $user->id)->max('send_started_at');
                $share = ShareDeliveryWindow::latest($user->id, $notification->id);
                if ($share && (! $last || $share > $last)) $last = $share;
                if ($last && CarbonImmutable::parse($last, 'UTC')->addHours(2)->gt($time)) return false;
                $count = PushReminder::where('user_id', $user->id)->where('dispatched_at', '>=', $time->startOfDay()->utc())->where('dispatched_at', '<', $time->addDay()->startOfDay()->utc())->count();
                if ($count + ShareDeliveryWindow::count($user->id, $time->startOfDay()->utc(), $time->addDay()->startOfDay()->utc(), $notification->id) >= 3) return false;
                DB::table('community_push_attempts')->updateOrInsert(['notification_id' => $notification->id, 'device_id' => $device->id],
                    ['status' => 'sending', 'attempts' => ($attempt?->attempts ?? 0) + 1, 'retry_at' => null,
                        'created_at' => $attempt?->created_at ?? now(), 'updated_at' => now()]);
                return true;
            }, 3);
            if (! $reserved) continue;
            try { $status = $sender->send($device, $notification); }
            catch (\Throwable) { $status = 'unknown'; }
            $attempts = DB::table('community_push_attempts')->where('notification_id', $notification->id)->where('device_id', $device->id)->value('attempts');
            if ($status === 'transient' && $attempts >= 3) $status = 'failed';
            DB::table('community_push_attempts')->where('notification_id', $notification->id)->where('device_id', $device->id)
                ->update(['status' => $status, 'retry_at' => $status === 'transient' ? now()->addMinutes(2) : null, 'updated_at' => now()]);
            if ($status === 'invalid') $device->delete();
            $retry = $retry || $status === 'transient';
        }
        // Ambiguous sends are never replayed; explicit transient failures can retry.
        if ($retry) $this->release(120);
    }
}
