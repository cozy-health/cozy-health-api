<?php
use App\Models\FeatureFlag;
use App\Models\User;
use App\Services\Analytics\AnalyticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
uses(RefreshDatabase::class);
beforeEach(function () { Mail::fake(); });

it('rejects unknown events and any properties even when analytics is disabled', function () {
    expect(fn () => app(AnalyticsService::class)->track('journal_content'))->toThrow(InvalidArgumentException::class);
    expect(fn () => app(AnalyticsService::class)->track('mood_logged', ['mood' => 'good']))->toThrow(InvalidArgumentException::class);
    expect(fn () => app(AnalyticsService::class)->track('user_logged_in', ['user_id' => 1]))->toThrow(InvalidArgumentException::class);
    $this->assertDatabaseCount('analytics_events', 0);
});

it('defaults analytics off and stores only the event name and timestamp when enabled', function () {
    app(AnalyticsService::class)->track('mood_logged'); $this->assertDatabaseCount('analytics_events', 0);
    FeatureFlag::where('key', 'analytics')->first()->update(['enabled' => true]);
    app(AnalyticsService::class)->track('mood_logged');
    $this->assertDatabaseCount('analytics_events', 1);
    expect(Schema::getColumnListing('analytics_events'))->toBe(['id', 'event', 'created_at']);
});

it('emits anonymous registration and login events on successful requests', function () {
    FeatureFlag::where('key', 'analytics')->first()->update(['enabled' => true]);
    $this->postJson('/api/v1/auth/register', ['name' => 'Private Name', 'email' => 'private@example.org', 'password' => 'password123', 'password_confirmation' => 'password123'])->assertCreated();
    $this->postJson('/api/v1/auth/login', ['email' => 'private@example.org', 'password' => 'password123'])->assertOk();
    $this->assertDatabaseHas('analytics_events', ['event' => 'user_registered']);
    $this->assertDatabaseHas('analytics_events', ['event' => 'user_logged_in']);
    expect(json_encode(DB::table('analytics_events')->get()))->not->toContain('Private Name')->not->toContain('private@example.org');
});

it('counts direct and synced record creation once and never their content', function () {
    FeatureFlag::where('key', 'analytics')->first()->update(['enabled' => true]);
    Sanctum::actingAs(User::factory()->create());
    $id = (string) Str::uuid();
    $payload = ['id' => $id, 'mood' => 'good', 'intensity' => 5, 'note' => 'private mood notes'];
    $this->postJson('/api/v1/mood-entries', $payload)->assertOk();
    $this->postJson('/api/v1/mood-entries', $payload)->assertOk();
    $this->getJson('/api/v1/mood-entries/'.$id)->assertOk();
    $op = ['type' => 'journal_entry', 'action' => 'upsert', 'data' => ['id' => (string) Str::uuid(), 'type' => 'free', 'body' => 'private journal']];
    foreach ([1, 2] as $_) { $this->postJson('/api/v1/sync/batch', ['operations' => [$op]])->assertOk()->assertJsonPath('data.results.0.success', true); }
    expect(DB::table('analytics_events')->where('event', 'mood_logged')->count())->toBe(1);
    expect(DB::table('analytics_events')->where('event', 'journal_created')->count())->toBe(1);
    expect(json_encode(DB::table('analytics_events')->get()))->not->toContain('private journal')->not->toContain('private mood');
});

it('protects analytics metrics and validates range', function () {
    $this->getJson('/admin/metrics/analytics')->assertUnauthorized();
    $user = User::factory()->create(); Sanctum::actingAs($user);
    $this->getJson('/admin/metrics/analytics')->assertForbidden();
    $user->forceFill(['role' => 'admin'])->save();
    app('auth')->forgetGuards();
    $this->withToken($user->createToken('cozy-admin-dashboard', ['admin:metrics'], now()->addMinutes(30))->plainTextToken);
    $this->getJson('/admin/metrics/analytics?range=1y')->assertUnprocessable();
});

it('suppresses small counts and populations', function () {
    $user = User::factory()->create(); $user->forceFill(['role' => 'admin'])->save(); Sanctum::actingAs($user);
    app('auth')->forgetGuards();
    $this->withToken($user->createToken('cozy-admin-dashboard', ['admin:metrics'], now()->addMinutes(30))->plainTextToken);
    for ($i = 0; $i < 10; $i++) { DB::table('analytics_events')->insert(['event' => 'mood_logged', 'created_at' => now()]); }
    $this->getJson('/admin/metrics/analytics?range=7d')->assertOk()->assertJsonPath('data.features.0.count', '<5')->assertJsonMissingPath('active_users')->assertJsonMissingPath('events');
});

it('does not publish anonymous event counts without distinct contributor proof', function () {
    $users = User::factory()->count(5)->create();
    foreach ($users as $user) { $user->createToken('test'); $user->createToken('another-device'); }
    $admin = $users->first(); $admin->forceFill(['role' => 'admin'])->save(); Sanctum::actingAs($admin);
    app('auth')->forgetGuards();
    $this->withToken($admin->createToken('cozy-admin-dashboard', ['admin:metrics'], now()->addMinutes(30))->plainTextToken);
    for ($i = 0; $i < 7; $i++) { DB::table('analytics_events')->insert(['event' => 'mood_logged', 'created_at' => now()]); }
    $this->getJson('/admin/metrics/analytics')->assertOk()->assertJsonPath('data.features.0.count', '<5')->assertJsonPath('data.unavailable_features.0', 'app_opens')->assertJsonMissingPath('events');
});

it('prunes only records beyond fourteen months and rejects excessive retention', function () {
    DB::table('analytics_events')->insert([
        ['event' => 'mood_logged', 'created_at' => now()->subMonthsNoOverflow(15)],
        ['event' => 'mood_logged', 'created_at' => now()->subMonthsNoOverflow(13)],
    ]);
    $this->artisan('analytics:prune')->assertSuccessful(); $this->assertDatabaseCount('analytics_events', 1);
    $this->artisan('analytics:prune', ['--older-than' => '15months'])->assertFailed();
});
