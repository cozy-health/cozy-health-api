<?php

namespace App\Services\Push;

use App\Jobs\SendPushAttempt;
use App\Models\{AppNotification, JournalEntry, MoodEntry, PushDevice, PushReminder, User, UserProfile};
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReminderScheduler
{
    public function enabled(): bool
    {
        return config('push.enabled') && (\App\Models\FeatureFlag::where('key', 'push_notifications')->value('enabled')
            ?? \App\Services\AppConfigService::DEFAULT_FLAGS['push_notifications']);
    }

    public function tick(): void
    {
        if (! $this->enabled()) return;
        // A killed worker may have sent before it stopped. Resolve conservatively without replay.
        DB::table('push_attempts')->where('status', 'sending')->where('updated_at', '<', now()->subMinutes(2))
            ->update(['status' => 'unknown', 'updated_at' => now()]);
        PushReminder::where('status', 'dispatching')->whereNotExists(function ($q) {
            $q->selectRaw('1')->from('push_attempts')->whereColumn('reminder_id', 'push_reminders.id')->whereIn('status', ['queued', 'sending', 'transient']);
        })->chunkById(100, function ($reminders) {
            foreach ($reminders as $reminder) {
                $states = DB::table('push_attempts')->where('reminder_id', $reminder->id)->pluck('status');
                $reminder->update(['status' => $states->contains('accepted') ? 'accepted' : ($states->contains('unknown') ? 'unknown' : 'failed')]);
            }
        });
        User::where('is_active', true)->where('is_demo', false)->chunkById(100, function ($users) {
            foreach ($users as $user) $this->schedule($user);
        });
        // Only explicitly rejected transient attempts can be retried.
        DB::table('push_attempts')->where('status', 'transient')->where('retry_at', '<=', now())
            ->orderBy('id')->chunkById(100, function ($attempts) {
                foreach ($attempts as $attempt) SendPushAttempt::dispatch($attempt->id);
            });
    }

    public function schedule(User $user): void
    {
        DB::transaction(function () use ($user) {
            $user = User::whereKey($user->id)->lockForUpdate()->first();
            $profile = UserProfile::withoutGlobalScope('user')->where('user_id', $user?->id)->first();
            if (! $user || ! $user->is_active || $user->is_demo || ! $profile?->push_consent || ! $profile->notifications_master) {
                PushReminder::where('user_id', $user?->id)->whereIn('status', ['queued', 'deferred'])->update(['status' => 'cancelled']);
                return;
            }
            $now = CarbonImmutable::now($user->timezone);
            $settings = ReminderSettings::for($profile);
            $date = $now->toDateString();
            $pending = PushReminder::where('user_id', $user->id)->whereIn('status', ['queued', 'deferred'])->get();
            foreach ($pending as $reminder) {
                if ($reminder->timezone !== $user->timezone) {
                    $reminder->update(['status' => 'cancelled']);
                } elseif ($reminder->category === 'mood' && $reminder->local_date !== $date && $reminder->expires_at->isFuture()) {
                    $scheduled = $now->startOfDay()->setTimeFromTimeString($profile->daily_checkin_time);
                    $eligible = $this->afterQuiet($now->startOfDay(), $settings);
                    $today = PushReminder::firstOrCreate(['user_id' => $user->id, 'category' => 'mood', 'local_date' => $date], [
                        'id' => (string) Str::uuid(), 'timezone' => $user->timezone, 'settings_hash' => $this->fingerprint($profile, $settings, 'mood'), 'scheduled_at' => $scheduled->utc(),
                        'eligible_at' => $eligible->utc(), 'expires_at' => $now->addDay()->startOfDay()->utc(), 'status' => 'deferred',
                    ]);
                    if (in_array($today->status, ['queued', 'deferred'])) $today->update(['eligible_at' => $eligible->utc()]);
                    $reminder->update(['status' => 'expired']);
                } elseif ($reminder->expires_at->lte($now)) {
                    $reminder->update(['status' => 'expired']);
                } elseif ($reminder->settings_hash !== $this->fingerprint($profile, $settings, $reminder->category)) {
                    $time = $reminder->category === 'mood' ? $profile->daily_checkin_time : $settings[$reminder->category.'_time'];
                    $scheduled = $now->startOfDay()->setTimeFromTimeString($time);
                    $eligible = $this->afterQuiet($scheduled, $settings);
                    $expires = $now->addDay()->startOfDay();
                    if ($reminder->category === 'mood' && $eligible->gte($expires)) $expires = $expires->addDay();
                    $reminder->update(['scheduled_at' => $scheduled->utc(), 'eligible_at' => $eligible->utc(), 'expires_at' => $expires->utc(),
                        'settings_hash' => $this->fingerprint($profile, $settings, $reminder->category)]);
                }
            }
            foreach (ReminderSettings::CATEGORIES as $category) {
                if (! $this->categoryEnabled($profile, $settings, $category)) {
                    PushReminder::where('user_id', $user->id)->where('category', $category)->whereIn('status', ['queued', 'deferred'])->update(['status' => 'cancelled']);
                    continue;
                }
                $time = $category === 'mood' ? $profile->daily_checkin_time : $settings[$category.'_time'];
                $scheduled = $now->startOfDay()->setTimeFromTimeString($time);
                if (! in_array($now->isoWeekday(), $settings[$category.'_days']) || $scheduled->gt($now)) continue;
                $eligible = $this->afterQuiet($scheduled, $settings);
                // A restart must not generate stale reminders, except a still-valid quiet-hours deferral.
                if ($eligible->lt($now->subMinutes(15))) continue;
                $expires = $now->addDay()->startOfDay();
                if ($category === 'mood' && $eligible->gte($expires)) $expires = $expires->addDay();
                PushReminder::firstOrCreate(['user_id' => $user->id, 'category' => $category, 'local_date' => $date], [
                    'id' => (string) Str::uuid(), 'timezone' => $user->timezone, 'settings_hash' => $this->fingerprint($profile, $settings, $category), 'scheduled_at' => $scheduled->utc(),
                    'eligible_at' => $eligible->utc(), 'expires_at' => $expires->utc(),
                ]);
            }
            $last = PushReminder::where('user_id', $user->id)->whereNotNull('dispatched_at')->max('dispatched_at');
            $actual = PushReminder::where('user_id', $user->id)->max('send_started_at');
            if ($actual && (! $last || $actual > $last)) $last = $actual;
            $share = ShareDeliveryWindow::latest($user->id);
            if ($share && (! $last || $share > $last)) $last = $share;
            $count = PushReminder::where('user_id', $user->id)->where('dispatched_at', '>=', $now->startOfDay()->utc())
                ->where('dispatched_at', '<', $now->addDay()->startOfDay()->utc())->count();
            $count += ShareDeliveryWindow::count($user->id, $now->startOfDay()->utc(), $now->addDay()->startOfDay()->utc());
            foreach (ReminderSettings::CATEGORIES as $category) {
                $reminder = PushReminder::where('user_id', $user->id)->where('category', $category)->where('local_date', $date)
                    ->whereIn('status', ['queued', 'deferred'])->first();
                if (! $reminder) continue;
                if ($count >= 3) { $reminder->update(['status' => 'expired']); continue; }
                if (! $this->eligible($user, $profile, $category, $now)) { $reminder->update(['status' => 'cancelled']); continue; }
                if ($reminder->eligible_at->gt($now)) continue;
                $eligible = $this->afterQuiet($now, $settings);
                if ($last) $eligible = $eligible->max(CarbonImmutable::parse($last, 'UTC')->addHours(2));
                $eligible = $this->afterQuiet($eligible->setTimezone($user->timezone), $settings);
                if ($eligible->gte($reminder->expires_at)) { $reminder->update(['status' => 'expired']); continue; }
                if ($eligible->gt($now)) { $reminder->update(['status' => 'deferred', 'eligible_at' => $eligible->utc()]); continue; }
                $devices = $this->devices($user->id)->get();
                if ($devices->isEmpty()) continue;
                if (! $this->enabled()) return;
                $reminder->update(['status' => 'dispatching', 'dispatched_at' => $now->utc(), 'dispatch_date' => $date]);
                [$title, $body, $route] = self::copy($category);
                if ($category === 'affirmation') {
                    $assignment = app(\App\Services\AffirmationAssignmentService::class)->current($user);
                    if (! $assignment) {
                        $reminder->update(['status' => 'cancelled']);
                        continue;
                    }
                    [$title, $body, $route] = ['Your daily affirmation', $assignment['body'], '/daily-affirmation'];
                }
                AppNotification::create(['id' => $reminder->id, 'user_id' => $user->id, 'type' => $category,
                    'title' => $title, 'body' => $body, 'deep_link' => $route, 'data' => ['category' => $category],
                    'is_read' => false, 'client_created_at' => $now->utc()]);
                foreach ($devices as $device) {
                    $id = DB::table('push_attempts')->insertGetId(['reminder_id' => $reminder->id, 'device_id' => $device->id,
                        'status' => 'queued', 'attempts' => 0, 'created_at' => now(), 'updated_at' => now()]);
                    SendPushAttempt::dispatch($id)->afterCommit();
                }
                $count++;
                $last = $now->utc()->toDateTimeString();
            }
        }, 3);
    }

    public function devices(int $userId)
    {
        return PushDevice::where('user_id', $userId)->where('provider', 'fcm')->where('expires_at', '>', now())
            ->when(! config('push.ios_enabled'), fn ($q) => $q->where('platform', 'android'));
    }

    private function fingerprint(UserProfile $profile, array $settings, string $category): string
    {
        return hash('sha256', json_encode([$category, $settings[$category.'_days'],
            $category === 'mood' ? $profile->daily_checkin_time : $settings[$category.'_time'],
            $this->categoryEnabled($profile, $settings, $category), $settings['quiet_enabled'], $settings['quiet_start'], $settings['quiet_end']], JSON_THROW_ON_ERROR));
    }

    public function categoryEnabled(UserProfile $profile, array $settings, string $category): bool
    {
        return match ($category) {
            'mood' => $profile->daily_checkin_enabled,
            'journal' => $profile->journal_reminder_enabled,
            default => (bool) $settings[$category.'_enabled'],
        };
    }

    public function eligible(User $user, UserProfile $profile, string $category, CarbonImmutable $now): bool
    {
        if (! app(\App\Services\AgeEligibility::class)->allows($user)) return false;
        if ($category === 'affirmation' && DB::table('user_affirmation_reads')
            ->where('user_id', $user->id)->where('local_date', $now->toDateString())->exists()) return false;
        $settings = ReminderSettings::for($profile);
        if (! $user->is_active || $user->is_demo || ! $profile->push_consent || ! $profile->notifications_master
            || ! $this->categoryEnabled($profile, $settings, $category) || ! in_array($now->isoWeekday(), $settings[$category.'_days'])) return false;
        $start = $now->startOfDay()->utc();
        $end = $now->addDay()->startOfDay()->utc();
        $moods = MoodEntry::withoutGlobalScope('user')->where('user_id', $user->id)->where('is_deleted', false);
        if ($category === 'mood' || $category === 'streak') {
            if ((clone $moods)->where('client_created_at', '>=', $start)->where('client_created_at', '<', $end)->exists()) return false;
            return $category === 'mood' || (clone $moods)->where('client_created_at', '>=', $now->subDay()->startOfDay()->utc())
                ->where('client_created_at', '<', $start)->exists();
        }
        return $category !== 'journal' || ! JournalEntry::withoutGlobalScope('user')->where('user_id', $user->id)
            ->where('is_deleted', false)->where('is_draft', false)->where('client_created_at', '>=', $start)->where('client_created_at', '<', $end)->exists();
    }

    public function afterQuiet(CarbonImmutable $time, array $settings): CarbonImmutable
    {
        if (! $settings['quiet_enabled']) return $time;
        $clock = $time->format('H:i');
        $start = $settings['quiet_start']; $end = $settings['quiet_end'];
        if ($start === $end) return $time; // Equal times mean no quiet interval.
        $inside = $start < $end ? ($clock >= $start && $clock < $end) : ($clock >= $start || $clock < $end);
        if (! $inside) return $time;
        $result = $time->setTimeFromTimeString($end)->setSecond(0);
        return $start > $end && $clock >= $start ? $result->addDay() : $result;
    }

    public static function copy(string $category): array
    {
        return match ($category) {
            'mood' => ['A moment for yourself', 'Check in with how you are feeling today.', '/mood-feeling'],
            'journal' => ['Space for your thoughts', 'Would you like to write a little today?', '/journal-compose'],
            'streak' => ['Your check-in journey', 'There is still time for a gentle check-in today.', '/home?focus=streak'],
            default => ['A gentle reminder', 'Small steps count. You can move at your own pace.', '/home?focus=affirmation'],
        };
    }
}
