<?php

namespace Tests\Feature;

use App\Models\ContentArticle;
use App\Models\CrisisResource;
use App\Models\MoodEntry;
use App\Models\PushDevice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class Phase16ContentAndCoreTest extends TestCase
{
    use RefreshDatabase;

    private function login(): User
    {
        $user = User::factory()->create();
        $this->withToken($user->createToken('device')->plainTextToken);

        return $user;
    }

    private function switchTo(User $user): self
    {
        app('auth')->forgetGuards();

        return $this->withToken($user->createToken('device')->plainTextToken);
    }

    private function article(array $overrides = []): ContentArticle
    {
        return ContentArticle::create([...[
            'id' => (string) Str::uuid(), 'slug' => 'article-'.Str::random(8),
            'title' => 'Fixture article', 'category' => 'sleep', 'excerpt' => 'Fixture excerpt',
            'body' => 'Fixture body', 'read_time_minutes' => 5, 'license' => 'Original fixture',
            'approved_at' => now(), 'published_at' => now()->subMinute(),
        ], ...$overrides]);
    }

    public function test_content_only_exposes_approved_published_licensed_articles(): void
    {
        $this->login();
        $visible = $this->article();
        $this->article(['approved_at' => null]);
        $this->article(['published_at' => now()->addDay()]);
        $this->article(['license' => null]);
        $this->article()->delete();
        $this->getJson('/api/v1/content/articles')->assertOk()->assertJsonPath('data.total', 1);
        $this->getJson('/api/v1/content/articles/'.$visible->slug)->assertOk()->assertJsonPath('data.body', 'Fixture body');
        $this->getJson('/api/v1/content/search?q=Fixture&category=sleep')->assertOk()->assertJsonPath('data.total', 1);
        $this->getJson('/api/v1/content/search')->assertUnprocessable();
        $this->getJson('/api/v1/content/articles?per_page=101')->assertUnprocessable();
        $this->getJson('/api/v1/content/categories')->assertOk()->assertJsonCount(6, 'data');
    }

    public function test_bookmarks_are_idempotent_scoped_restorable_and_keep_offline_body(): void
    {
        $user = $this->login();
        $article = $this->article();
        $path = '/api/v1/content/articles/'.$article->id.'/bookmark';
        $first = $this->postJson($path)->assertOk()->assertJsonPath('data.body', 'Fixture body')->json('data.id');
        $this->postJson($path)->assertOk()->assertJsonPath('data.id', $first);
        $this->getJson('/api/v1/content/bookmarks')->assertOk()->assertJsonPath('data.total', 1);
        $other = User::factory()->create();
        $this->switchTo($other)->getJson('/api/v1/content/saved')->assertJsonPath('data.total', 0);
        $this->switchTo($user)->deleteJson($path)->assertOk();
        $this->getJson('/api/v1/content/saved')->assertJsonPath('data.total', 0);
        $this->postJson('/api/v1/content/articles/'.$article->slug.'/save')->assertOk()->assertJsonPath('data.id', $first);
        $article->update(['approved_at' => null]);
        $this->getJson('/api/v1/content/articles/'.$article->slug)->assertNotFound();
        $this->deleteJson('/api/v1/content/articles/'.$article->slug.'/save')->assertOk();
    }

    public function test_content_search_treats_percent_and_underscore_as_literal_text(): void
    {
        $this->login();
        $this->article(['title' => '100% fixture']);
        $this->article(['title' => 'plain fixture']);
        $this->getJson('/api/v1/content/search?q=%25')->assertOk()->assertJsonPath('data.total', 1);
        $this->getJson('/api/v1/content/search?q=_')->assertOk()->assertJsonPath('data.total', 0);
    }

    public function test_push_tokens_are_encrypted_private_rotatable_and_not_reassignable(): void
    {
        $user = $this->login();
        $token = str_repeat('a', 64);
        $body = ['device_id' => 'device-1', 'platform' => 'ios', 'provider' => 'apns', 'token' => $token];
        $this->postJson('/api/v1/notifications/register-token', $body)->assertOk()->assertJsonMissing(['token' => $token]);
        $device = PushDevice::firstOrFail();
        $this->assertSame($token, $device->token);
        $this->assertNotSame($token, $device->getRawOriginal('token'));
        $this->postJson('/api/v1/notifications/register-token', $body)->assertOk();
        $this->assertDatabaseCount('push_devices', 1);
        $other = User::factory()->create();
        $this->switchTo($other)
            ->postJson('/api/v1/notifications/register-token', $body)->assertConflict();
        $this->deleteJson('/api/v1/notifications/devices/device-1')->assertOk();
        $this->assertDatabaseCount('push_devices', 1);
        $this->switchTo($user)
            ->postJson('/api/v1/notifications/register-token', [...$body, 'token' => str_repeat('b', 64)])->assertOk();
        $this->assertDatabaseCount('push_devices', 1);
        $this->deleteJson('/api/v1/notifications/devices/device-1')->assertOk();
        $this->assertDatabaseCount('push_devices', 0);
    }

    public function test_notifications_validate_times_and_preferences_persist(): void
    {
        $this->login();
        $this->patchJson('/api/v1/notifications/preferences', ['daily_checkin_time' => '25:70'])->assertUnprocessable();
        $this->patchJson('/api/v1/notifications/preferences', ['daily_checkin_time' => '07:30', 'marketing_notifications' => false])->assertOk();
        $this->getJson('/api/v1/notifications/preferences')->assertOk()->assertJsonPath('data.daily_checkin_time', '07:30');
        $this->postJson('/api/v1/notifications/register-token', ['device_id' => 'x', 'platform' => 'android', 'provider' => 'apns', 'token' => 'bad'])->assertUnprocessable();
    }

    public function test_mood_stats_use_local_boundaries_and_ignore_deleted_other_users_and_future_records(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 10)->setTime(1, 0));
        $user = $this->login();
        $user->update(['timezone' => 'Asia/Tokyo']);
        $row = ['id' => (string) Str::uuid(), 'user_id' => $user->id, 'mood' => 'anxious', 'intensity' => 8, 'client_created_at' => '2026-10-09 16:00:00'];
        MoodEntry::create($row);
        MoodEntry::create([...$row, 'id' => (string) Str::uuid(), 'is_deleted' => true]);
        MoodEntry::create([...$row, 'id' => (string) Str::uuid(), 'client_created_at' => '2026-10-10 16:00:00']);
        MoodEntry::create([...$row, 'id' => (string) Str::uuid(), 'user_id' => User::factory()->create()->id]);
        $this->getJson('/api/v1/mood-entries/stats?days=1')->assertOk()->assertJsonPath('data.entry_count', 1)
            ->assertJsonPath('data.start_date', '2026-10-10')->assertJsonPath('data.average_intensity', 8);
        $this->getJson('/api/v1/mood-entries/stats?days=366')->assertUnprocessable();
    }

    public function test_crisis_resources_never_guess_country_or_return_unreviewed_stale_contacts(): void
    {
        $this->login();
        $this->getJson('/api/v1/crisis/resources')->assertOk()->assertJsonPath('meta.region_required', true);
        $this->patchJson('/api/v1/user/profile', ['country_code' => 'NG'])->assertOk();
        $row = ['country_code' => 'NG', 'name' => 'Reviewed fixture', 'phone' => 'fixture-only', 'is_active' => true, 'verified_at' => now(), 'reviewed_at' => now()];
        CrisisResource::create($row);
        CrisisResource::create([...$row, 'country_code' => 'US']);
        CrisisResource::create([...$row, 'reviewed_at' => null]);
        CrisisResource::create([...$row, 'verified_at' => now()->subMonths(7)]);
        $this->getJson('/api/v1/crisis/resources')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.country_code', 'NG');
        $this->getJson('/api/v1/crisis/resources?country_code=US')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/crisis/resources?country_code=unknown')->assertUnprocessable();
    }

    public function test_new_core_routes_require_authentication(): void
    {
        foreach (['content/articles', 'content/categories', 'notifications/preferences', 'crisis/resources', 'mood-entries/stats'] as $path) {
            $this->getJson('/api/v1/'.$path)->assertUnauthorized();
        }
        $this->postJson('/api/v1/notifications/register-token', [])->assertUnauthorized();
    }
}
