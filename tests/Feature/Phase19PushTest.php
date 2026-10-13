<?php
namespace Tests\Feature;
use App\Jobs\SendQuizSharePush;
use App\Models\{AppNotification, PushDevice, PushReminder, QuizAttempt, User, UserProfile};
use App\Services\QuizSharingService;
use App\Services\Push\{PushSender, ReminderScheduler};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class Phase19PushTest extends TestCase
{
    use RefreshDatabase;
    private object $sender;
    protected function setUp(): void
    {
        parent::setUp(); config(['push.enabled' => true]); $this->travelTo(now()->setDate(2026, 10, 13)->setTime(12, 0));
        \Illuminate\Support\Facades\Queue::fake([SendQuizSharePush::class]);
        $this->sender = new class implements PushSender {
            public array $sent = [];
            public function send(PushDevice $device, AppNotification $notification): string { $this->sent[] = $notification; return 'accepted'; }
        };
    }
    private function notice(): array
    {
        $owner = User::factory()->create(); $recipient = User::factory()->create(['timezone' => 'UTC']);
        $profile = UserProfile::create(['id' => (string) Str::uuid(), 'user_id' => $recipient->id, 'name' => $recipient->name, 'email' => $recipient->email,
            'push_consent' => true, 'notifications_master' => true, 'daily_checkin_enabled' => true, 'daily_checkin_time' => '12:00', 'reminder_settings' => ['quiet_enabled' => false]]);
        $device = PushDevice::create(['user_id' => $recipient->id, 'device_id' => 'fixture', 'platform' => 'android', 'provider' => 'fcm',
            'token' => str_repeat('x', 64), 'token_hash' => hash('sha256', 'fixture-'.$recipient->id), 'expires_at' => now()->addDays(90)]);
        $attempt = QuizAttempt::create(['id' => (string) Str::uuid(), 'user_id' => $owner->id, 'quiz_slug' => 'fixture', 'quiz_title' => 'Private fixture title',
            'answers' => [0], 'score' => 0, 'interpretation' => 'Private interpretation', 'completed_at' => now(), 'validated_at' => now()]);
        $share = app(QuizSharingService::class)->friend($owner->id, $attempt->id, $recipient->id);
        $notification = AppNotification::withoutGlobalScope('user')->create(['id' => (string) Str::uuid(), 'user_id' => $recipient->id,
            'type' => 'quiz_share', 'title' => 'A result was shared with you', 'body' => 'Open Cozy Health to view it.',
            'deep_link' => '/quiz-shared/'.$share->id, 'data' => ['share_id' => $share->id], 'client_created_at' => now()]);
        return [$owner, $recipient, $profile, $device, $share, $notification];
    }
    private function send(AppNotification $notification): void { (new SendQuizSharePush($notification->id))->handle($this->sender, app(ReminderScheduler::class)); }
    public function test_notices_are_idempotent_and_do_not_include_result_content(): void
    {
        [, , , , , $notification] = $this->notice(); $this->send($notification); $this->send($notification);
        $this->assertCount(1, $this->sender->sent);
        $this->assertDatabaseCount('quiz_push_attempts', 1);
        $this->assertStringNotContainsString('Private', $this->sender->sent[0]->title.$this->sender->sent[0]->body);
    }
    public function test_revocation_consent_withdrawal_and_quiet_hours_block_delivery(): void
    {
        [, , $profile, , $share, $notification] = $this->notice();
        DB::table('quiz_friend_shares')->where('id', $share->id)->update(['revoked_at' => now()]); $this->send($notification);
        DB::table('quiz_friend_shares')->where('id', $share->id)->update(['revoked_at' => null]);
        $profile->update(['reminder_settings' => ['quiet_enabled' => true, 'quiet_start' => '11:00', 'quiet_end' => '13:00']]); $this->send($notification);
        $profile->update(['push_consent' => false]); $this->send($notification);
        $this->assertCount(0, $this->sender->sent); $this->assertDatabaseCount('quiz_push_attempts', 0);
    }
    public function test_share_and_reminder_deliveries_use_the_same_spacing_window(): void
    {
        [, $recipient, , , , $notification] = $this->notice(); $this->send($notification);
        app(ReminderScheduler::class)->schedule($recipient);
        $this->assertDatabaseHas('push_reminders', ['user_id' => $recipient->id, 'category' => 'mood', 'status' => 'deferred']);
        $this->assertSame(now()->addHours(2)->format('Y-m-d H:i:s'), PushReminder::where('user_id', $recipient->id)->where('category', 'mood')->first()->eligible_at->format('Y-m-d H:i:s'));
    }
    public function test_three_share_notices_use_one_daily_allowance_per_notification(): void
    {
        [, $recipient, , $device, , $notification] = $this->notice();
        foreach ([6, 4, 2] as $hours) {
            $previous = AppNotification::withoutGlobalScope('user')->create(['id' => (string) Str::uuid(), 'user_id' => $recipient->id, 'type' => 'quiz_share', 'title' => 'Notice', 'body' => 'Open the app.', 'client_created_at' => now()]);
            DB::table('quiz_push_attempts')->insert(['notification_id' => $previous->id, 'device_id' => $device->id, 'status' => 'accepted', 'created_at' => now()->subHours($hours), 'updated_at' => now()]);
        }
        $this->send($notification); $this->assertCount(0, $this->sender->sent);
    }
}
