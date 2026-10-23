<?php
namespace Tests\Feature;

use App\Jobs\SendCommunityPush;
use App\Models\{AppNotification, FeatureFlag, PushDevice, User, UserProfile};
use App\Services\CommunityNotifications;
use App\Services\Push\{PushSender, ReminderScheduler};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Queue};
use Illuminate\Support\Str;
use Tests\TestCase;

class CommunityNotificationTest extends TestCase
{
    use RefreshDatabase;
    private User $owner;
    private User $actor;
    private object $post;
    private UserProfile $profile;

    protected function setUp(): void
    {
        parent::setUp(); Queue::fake();
        config(['push.enabled' => true, 'community.release_ready' => true, 'community.guidelines_version' => 'test-1']);
        foreach (['push_notifications', 'community'] as $key) FeatureFlag::updateOrCreate(['key' => $key], ['enabled' => true]);
        $this->owner = User::factory()->create(['timezone' => 'UTC']);
        $this->actor = User::factory()->create();
        $this->profile = UserProfile::create(['id' => (string) Str::uuid(), 'user_id' => $this->owner->id,
            'name' => $this->owner->name, 'email' => $this->owner->email, 'notifications_master' => true,
            'comments_notifications' => true, 'push_consent' => true, 'reminder_settings' => ['quiet_enabled' => false]]);
        $id = (string) Str::uuid();
        DB::table('community_posts')->insert(['id' => $id, 'user_id' => $this->owner->id, 'body' => 'Private fixture body',
            'status' => 'published', 'guidelines_version' => 'test-1', 'guidelines_accepted_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $this->post = DB::table('community_posts')->find($id);
        PushDevice::create(['user_id' => $this->owner->id, 'device_id' => 'synthetic-device', 'platform' => 'android',
            'provider' => 'fcm', 'token' => str_repeat('x', 64), 'token_hash' => hash('sha256', 'synthetic-token'), 'expires_at' => now()->addDay()]);
    }

    private function like(): AppNotification
    {
        DB::table('community_reactions')->insertOrIgnore(['user_id' => $this->actor->id, 'target_type' => 'post', 'target_id' => $this->post->id]);
        app(CommunityNotifications::class)->create('like', $this->post, $this->actor->id);
        return AppNotification::withoutGlobalScope('user')->firstOrFail();
    }

    private function runJob(AppNotification $notice, string $outcome = 'accepted'): object
    {
        $sender = new class($outcome) implements PushSender {
            public int $calls = 0;
            public function __construct(private string $outcome) {}
            public function send(PushDevice $device, AppNotification $notification): string { $this->calls++; return $this->outcome; }
        };
        (new SendCommunityPush($notice->id))->handle($sender, app(ReminderScheduler::class), app(CommunityNotifications::class));
        return $sender;
    }

    public function test_http_likes_are_owned_idempotent_and_do_not_leak_identity_or_post_text(): void
    {
        $this->withToken($this->actor->createToken('test')->plainTextToken);
        foreach ([true, true, false, true] as $liked) $this->putJson('/api/v1/community/posts/'.$this->post->id.'/reaction', ['liked' => $liked])->assertOk();
        $this->assertDatabaseCount('app_notifications', 1);
        $notice = AppNotification::withoutGlobalScope('user')->firstOrFail();
        $this->assertSame($this->owner->id, $notice->user_id);
        $this->assertSame(['post_id' => $this->post->id], $notice->data);
        $this->assertStringNotContainsString('Private fixture', $notice->body);
        $this->getJson('/api/v1/notifications')->assertOk()->assertJsonPath('data.total', 0);
    }

    public function test_replies_notify_author_but_pending_and_self_replies_do_not(): void
    {
        $this->withToken($this->actor->createToken('test')->plainTextToken);
        $path = '/api/v1/community/posts/'.$this->post->id.'/comments';
        $this->postJson($path, ['body' => 'A reply', 'is_anonymous' => true])->assertOk();
        $this->assertDatabaseHas('app_notifications', ['user_id' => $this->owner->id, 'type' => 'reply']);
        $this->postJson($path, ['body' => 'hurt myself'])->assertOk();
        $this->assertDatabaseCount('app_notifications', 1);
        app('auth')->forgetGuards(); $this->withToken($this->owner->createToken('test')->plainTextToken);
        $this->postJson($path, ['body' => 'My own reply'])->assertOk();
        $this->assertDatabaseCount('app_notifications', 1);
    }

    public function test_preferences_and_blocks_prevent_creation(): void
    {
        $this->profile->update(['comments_notifications' => false, 'reminder_settings' => ['community_likes_enabled' => false]]);
        foreach (['like', 'reply'] as $type) app(CommunityNotifications::class)->create($type, $this->post, $this->actor->id);
        $this->assertDatabaseCount('app_notifications', 0);
        $this->profile->update(['reminder_settings' => ['community_likes_enabled' => true]]);
        DB::table('community_blocks')->insert(['user_id' => $this->actor->id, 'blocked_user_id' => $this->owner->id, 'created_at' => now(), 'updated_at' => now()]);
        app(CommunityNotifications::class)->create('like', $this->post, $this->actor->id);
        $this->assertDatabaseCount('app_notifications', 0);
    }

    public function test_accepted_push_cannot_be_replayed(): void
    {
        $notice = $this->like();
        $this->assertSame(1, $this->runJob($notice)->calls);
        $this->assertSame(0, $this->runJob($notice)->calls);
        $this->assertDatabaseHas('community_push_attempts', ['notification_id' => $notice->id, 'status' => 'accepted']);
    }

    public function test_phone_preferences_preserve_inbox_and_removed_content_prevents_send(): void
    {
        $notice = $this->like();
        $this->profile->update(['reminder_settings' => ['community_push_enabled' => false]]);
        $this->assertSame(0, $this->runJob($notice)->calls);
        $this->assertDatabaseCount('app_notifications', 1);
        $this->profile->update(['reminder_settings' => ['community_push_enabled' => true]]);
        DB::table('community_posts')->where('id', $this->post->id)->update(['status' => 'removed']);
        $this->assertSame(0, $this->runJob($notice)->calls);
    }

    public function test_device_reassignment_prevents_old_account_push(): void
    {
        $notice = $this->like();
        PushDevice::query()->update(['user_id' => $this->actor->id]);
        $this->assertSame(0, $this->runJob($notice)->calls);
    }

    public function test_transient_failures_retry_but_ambiguous_outcomes_do_not(): void
    {
        $notice = $this->like();
        $this->assertSame(1, $this->runJob($notice, 'transient')->calls);
        $this->assertSame(0, $this->runJob($notice)->calls);
        $this->travel(3)->minutes();
        $this->assertSame(1, $this->runJob($notice, 'unknown')->calls);
        $this->travel(3)->minutes();
        $this->assertSame(0, $this->runJob($notice)->calls);
    }

    public function test_settings_accept_community_controls(): void
    {
        $this->withToken($this->owner->createToken('test')->plainTextToken);
        $this->patchJson('/api/v1/user/notification-preferences', ['comments_notifications' => false,
            'reminder_settings' => ['community_likes_enabled' => false, 'community_push_enabled' => false]])->assertOk();
        $this->assertFalse($this->profile->fresh()->comments_notifications);
        $this->assertFalse($this->profile->fresh()->reminder_settings['community_push_enabled']);
    }

    public function test_worker_uses_the_same_guarded_development_availability_as_the_api(): void
    {
        config(['community.release_ready' => false, 'community.development_testing' => true,
            'community.railway_project_id' => \App\Services\CommunityAvailability::DEVELOPMENT_PROJECT,
            'community.railway_environment_id' => \App\Services\CommunityAvailability::DEVELOPMENT_ENVIRONMENT,
            'app.url' => 'https://api-dev.cozyhealth.org']);
        $notice = $this->like();
        config(['community.railway_environment_id' => 'other-environment']);
        $this->assertSame(0, $this->runJob($notice)->calls);
        config(['community.railway_environment_id' => \App\Services\CommunityAvailability::DEVELOPMENT_ENVIRONMENT]);
        $this->assertSame(1, $this->runJob($notice)->calls);
    }

    public function test_withdrawn_consent_quiet_hours_and_feature_shutdown_prevent_phone_delivery(): void
    {
        $notice = $this->like();
        config(['community.release_ready' => false]);
        $this->assertSame(0, $this->runJob($notice)->calls);
        config(['community.release_ready' => true]);
        $this->profile->update(['reminder_settings' => ['quiet_enabled' => true, 'quiet_start' => '00:00', 'quiet_end' => '23:59']]);
        $this->travelTo(now()->startOfDay()->addHours(12));
        $this->assertSame(0, $this->runJob($notice)->calls);
        $this->profile->update(['reminder_settings' => ['quiet_enabled' => false]]);
        $documents = config('consent.documents');
        foreach ($documents as $type => &$document) {
            if (! $document['required']) continue;
            $document = [...$document, 'published' => true, 'version' => 'push-test-1', 'content' => 'Synthetic approved document.'];
        }
        unset($document);
        app(\App\Services\SettingsService::class)->set('consent.documents', $documents);
        app(\App\Services\SettingsService::class)->set('consent.enforce_blocking', true);
        $this->assertSame(0, $this->runJob($notice)->calls);
        app(\App\Services\SettingsService::class)->set('consent.enforce_blocking', false);
        $this->profile->update(['push_consent' => false]);
        $this->assertSame(0, $this->runJob($notice)->calls);
    }
}
