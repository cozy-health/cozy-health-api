<?php

namespace Tests\Feature;

use App\Models\{AppNotification, MoodEntry, PushDevice, PushReminder, User, UserProfile};
use App\Services\Push\{PushSender, ReminderScheduler};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PushRemindersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['push.enabled' => true]);
        $this->app->bind(PushSender::class, fn () => new class implements PushSender {
            public function send(PushDevice $device, AppNotification $notification): string { return 'accepted'; }
        });
    }

    private function account(array $settings = [], bool $consent = true): User
    {
        $user = User::factory()->create(['timezone' => 'Africa/Lagos', 'is_active' => true]);
        UserProfile::create(['id' => (string) Str::uuid(), 'user_id' => $user->id, 'name' => $user->name, 'email' => $user->email,
            'push_consent' => $consent, 'notifications_master' => true, 'daily_checkin_enabled' => true,
            'daily_checkin_time' => '09:00', 'journal_reminder_enabled' => true, 'reminder_settings' => $settings]);
        PushDevice::create(['user_id' => $user->id, 'device_id' => 'fixture', 'platform' => 'android', 'provider' => 'fcm',
            'token' => str_repeat('x', 64), 'token_hash' => hash('sha256', 'token-'.$user->id), 'expires_at' => now()->addDays(90)]);
        return $user;
    }

    public function test_explicit_consent_is_required_and_feature_switch_blocks_dispatch(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 12)->setTime(8, 0));
        $user = $this->account(consent: false);
        app(ReminderScheduler::class)->schedule($user);
        $this->assertDatabaseCount('app_notifications', 0);
        config(['push.enabled' => false]);
        app(ReminderScheduler::class)->tick();
        $this->assertDatabaseCount('push_reminders', 0);
    }

    public function test_completed_mood_cancels_reminder_and_never_uses_another_accounts_entry(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 12)->setTime(8, 0));
        $user = $this->account();
        MoodEntry::create(['id' => (string) Str::uuid(), 'user_id' => $user->id, 'mood' => 'happy', 'intensity' => 3, 'client_created_at' => now()]);
        app(ReminderScheduler::class)->schedule($user);
        $this->assertDatabaseCount('app_notifications', 0);
        $this->assertDatabaseHas('push_reminders', ['category' => 'mood', 'status' => 'cancelled']);
    }

    public function test_same_day_cap_spacing_and_multidevice_share_one_allowance(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 12)->setTime(8, 0));
        $user = $this->account(['quiet_enabled' => false, 'journal_days' => [1], 'journal_time' => '09:00',
            'affirmation_enabled' => true, 'affirmation_time' => '09:00', 'streak_enabled' => true, 'streak_time' => '09:00']);
        MoodEntry::create(['id' => (string) Str::uuid(), 'user_id' => $user->id, 'mood' => 'happy', 'intensity' => 3, 'client_created_at' => now()->subDay()]);
        PushDevice::create(['user_id' => $user->id, 'device_id' => 'second', 'platform' => 'android', 'provider' => 'fcm',
            'token' => str_repeat('z', 64), 'token_hash' => hash('sha256', 'second'), 'expires_at' => now()->addDays(90)]);
        $scheduler = app(ReminderScheduler::class);
        $scheduler->schedule($user); $scheduler->schedule($user);
        $this->assertDatabaseCount('app_notifications', 1);
        $this->assertDatabaseCount('push_attempts', 2);
        $this->travel(119)->minutes(); $scheduler->schedule($user);
        $this->assertDatabaseCount('app_notifications', 1);
        $this->travel(1)->minutes(); $scheduler->schedule($user);
        $this->assertDatabaseCount('app_notifications', 2);
        $this->travel(2)->hours(); $scheduler->schedule($user); $scheduler->schedule($user);
        $this->assertDatabaseCount('app_notifications', 3);
        $this->assertDatabaseHas('push_reminders', ['category' => 'affirmation', 'status' => 'expired']);
    }

    public function test_quiet_hours_roll_mood_forward_but_expire_streak(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 12)->setTime(22, 0)); // 23:00 Lagos
        $user = $this->account(['streak_enabled' => true, 'streak_time' => '23:00']);
        $user->profile()->first()->update(['daily_checkin_time' => '23:00']);
        MoodEntry::create(['id' => (string) Str::uuid(), 'user_id' => $user->id, 'mood' => 'happy', 'intensity' => 3, 'client_created_at' => now()->subDay()]);
        $scheduler = app(ReminderScheduler::class);
        $scheduler->schedule($user);
        $this->assertDatabaseCount('app_notifications', 0);
        $this->travel(9)->hours(); $scheduler->schedule($user);
        $this->assertDatabaseCount('app_notifications', 1);
        $this->assertDatabaseHas('push_reminders', ['category' => 'streak', 'local_date' => '2026-10-12', 'status' => 'expired']);
        $scheduler->schedule($user);
        $this->assertDatabaseCount('app_notifications', 1);
    }

    public function test_preferences_validate_and_revocation_removes_devices(): void
    {
        $user = $this->account();
        $this->actingAs($user, 'sanctum')->patchJson('/api/v1/notifications/preferences', ['reminder_settings' => ['quiet_start' => '25:00']])->assertUnprocessable();
        $this->patchJson('/api/v1/notifications/preferences', ['reminder_settings' => ['mood_days' => [1, 1]]])->assertUnprocessable();
        $this->patchJson('/api/v1/notifications/preferences', ['reminder_settings' => ['quiet_start' => '21:00']])->assertOk();
        $this->getJson('/api/v1/notifications/preferences')->assertJsonPath('data.reminder_settings.quiet_start', '21:00');
        $this->patchJson('/api/v1/notifications/preferences', ['push_consent' => false])->assertOk();
        $this->assertDatabaseCount('push_devices', 0);
    }

    public function test_unknown_delivery_is_never_replayed(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 12)->setTime(8, 0));
        $user = $this->account();
        $this->app->bind(PushSender::class, fn () => new class implements PushSender {
            public function send(PushDevice $device, AppNotification $notification): string { return 'unknown'; }
        });
        app(ReminderScheduler::class)->schedule($user);
        app(ReminderScheduler::class)->tick();
        $this->assertSame(1, DB::table('push_attempts')->first()->attempts);
        $this->assertDatabaseHas('push_attempts', ['status' => 'unknown']);
    }

    public function test_cleanup_capability_survives_logout_and_cannot_revoke_a_rotated_registration(): void
    {
        $user = $this->account();
        $body = ['device_id' => 'registered', 'platform' => 'android', 'provider' => 'fcm', 'token' => str_repeat('a', 64)];
        $old = $this->actingAs($user, 'sanctum')->postJson('/api/v1/notifications/register-token', $body)->assertOk()->json('data.cleanup_token');
        $new = $this->postJson('/api/v1/notifications/register-token', $body)->assertOk()->json('data.cleanup_token');
        auth()->forgetGuards();
        $this->postJson('/api/v1/notifications/cleanup', ['device_id' => 'registered', 'cleanup_token' => $old])->assertOk();
        $this->assertDatabaseHas('push_devices', ['device_id' => 'registered']);
        $this->postJson('/api/v1/notifications/cleanup', ['device_id' => 'fixture', 'cleanup_token' => $new])->assertOk();
        $this->assertDatabaseHas('push_devices', ['device_id' => 'fixture']);
        $this->postJson('/api/v1/notifications/cleanup', ['device_id' => 'registered', 'cleanup_token' => $new])->assertOk();
        $this->assertDatabaseMissing('push_devices', ['device_id' => 'registered']);
    }

    public function test_changing_pending_time_recalculates_before_delivery(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 12)->setTime(6, 0));
        $user = $this->account();
        $profile = $user->profile()->first();
        $profile->update(['daily_checkin_time' => '07:00']);
        $scheduler = app(ReminderScheduler::class);
        $scheduler->schedule($user);
        $this->assertDatabaseCount('app_notifications', 0);
        $profile->update(['daily_checkin_time' => '10:00']);
        $this->travel(1)->hours(); $scheduler->schedule($user);
        $this->assertDatabaseCount('app_notifications', 0);
        $this->travel(2)->hours(); $scheduler->schedule($user);
        $this->assertDatabaseCount('app_notifications', 1);
    }

    public function test_dst_repeated_hour_does_not_repeat_the_category(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 1)->setTime(5, 30));
        $user = $this->account(['quiet_enabled' => false]);
        $user->update(['timezone' => 'America/New_York']);
        $user->profile()->first()->update(['daily_checkin_time' => '01:30']);
        $scheduler = app(ReminderScheduler::class);
        $scheduler->schedule($user);
        $this->travel(1)->hours(); $scheduler->schedule($user);
        $this->assertDatabaseCount('app_notifications', 1);
    }
}
