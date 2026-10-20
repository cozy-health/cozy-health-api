<?php

use App\Models\{AppNotification, ChatConversation, ChatMessage, JournalEntry, MoodEntry, QuizAttempt, SafetyPlan, SubscriptionStatus, User, UserProfile};
use Database\Seeders\{DatabaseSeeder, DemoAccountSeeder};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo(now()->startOfDay()->setTime(12, 0));
    $this->seed(DemoAccountSeeder::class);
    $this->demoUser = User::where('email', DemoAccountSeeder::EMAIL)->firstOrFail();
});

test('test_demo_account_can_log_in', function () {
    $this->postJson('/api/v1/auth/login', ['email' => DemoAccountSeeder::EMAIL, 'password' => DemoAccountSeeder::PASSWORD])
        ->assertOk()->assertJsonPath('data.user.name', 'Sample User')->assertJsonPath('data.user.is_demo', true)
        ->assertJsonStructure(['data' => ['token']]);
    expect($this->demoUser->is_active)->toBeTrue();
    expect($this->demoUser->bypassesPaywall())->toBeTrue();
    expect($this->demoUser->bypassesTwoFactorAuthentication())->toBeTrue();
    expect($this->demoUser->tokens()->first()->expires_at->timestamp)->toBe(now()->addHour()->timestamp);
});

test('test_demo_account_has_full_data', function () {
    foreach (['mood_entries' => 30, 'journal_entries' => 10, 'chat_conversations' => 5, 'chat_messages' => 10,
        'quiz_attempts' => 2, 'safety_plans' => 1, 'app_notifications' => 5, 'user_profiles' => 1, 'subscription_statuses' => 1] as $table => $count) {
        expect(DB::table($table)->where('user_id', $this->demoUser->id)->count())->toBe($count);
    }
    Sanctum::actingAs($this->demoUser);
    $this->getJson('/api/v1/user/profile')->assertOk()
        ->assertJsonPath('data.preferences.check_in_frequency', 'daily')
        ->assertJsonPath('data.preferences.skipped', false);
    $this->getJson('/api/v1/notifications')->assertOk()->assertJsonCount(5, 'data.data');
    expect(AppNotification::where('is_read', false)->count())->toBe(0);
    $this->getJson('/api/v1/subscription')->assertOk()->assertJsonPath('data.plan', 'pro')
        ->assertJsonPath('data.status', 'active')->assertJsonPath('data.expires_at', null);
});

test('test_demo_account_has_moods', function () {
    Sanctum::actingAs($this->demoUser);
    $this->getJson('/api/v1/mood-entries?per_page=50')->assertOk()->assertJsonCount(30, 'data.data');
    expect(MoodEntry::distinct()->count('mood'))->toBeGreaterThan(2);
    expect(MoodEntry::min('client_created_at'))->toStartWith(now()->subDays(29)->toDateString());
    expect(MoodEntry::max('client_created_at'))->toStartWith(now()->toDateString());
});

test('test_demo_account_has_journals', function () {
    Sanctum::actingAs($this->demoUser);
    $this->getJson('/api/v1/journal-entries')->assertOk()->assertJsonCount(10, 'data.data');
    expect(JournalEntry::distinct()->pluck('type')->sort()->values()->all())->toBe(['free', 'guided', 'voice']);
    foreach (JournalEntry::where('type', 'voice')->get() as $entry) {
        expect($entry->transcription)->toBe($entry->body);
        expect($entry->voice_duration)->toBeGreaterThan(0);
        expect(is_file(public_path(parse_url($entry->voice_url, PHP_URL_PATH))))->toBeTrue();
        expect(filesize(public_path(parse_url($entry->voice_url, PHP_URL_PATH))))->toBeGreaterThan(1000);
    }
});

test('test_demo_account_has_conversations', function () {
    Sanctum::actingAs($this->demoUser);
    $this->getJson('/api/v1/conversations')->assertOk()->assertJsonCount(5, 'data.data');
    foreach (ChatConversation::all() as $conversation) {
        expect($conversation->message_count)->toBe(2);
        expect(ChatMessage::where('conversation_id', $conversation->id)->count())->toBe(2);
        $this->getJson("/api/v1/conversations/{$conversation->id}/messages")
            ->assertOk()->assertJsonCount(2, 'data.data')
            ->assertJsonPath('data.data.0.role', 'user')->assertJsonPath('data.data.1.role', 'assistant');
    }
});

test('test_demo_account_has_quiz_attempts', function () {
    Sanctum::actingAs($this->demoUser);
    $this->getJson('/api/v1/quiz-attempts')->assertOk()->assertJsonCount(2, 'data.data');
    expect(QuizAttempt::orderBy('score')->pluck('interpretation')->all())->toBe(['Minimal anxiety', 'Mild anxiety']);
    foreach (QuizAttempt::all() as $attempt) {
        expect(array_sum($attempt->answers))->toBe($attempt->score);
    }
});

test('test_demo_account_has_safety_plan', function () {
    Sanctum::actingAs($this->demoUser);
    $this->getJson('/api/v1/safety-plan')->assertOk()->assertJsonPath('data.is_complete', true)
        ->assertJsonPath('data.people.0.name', 'Sarah Chen');
    $plan = SafetyPlan::first();
    foreach (['warning_signs', 'coping_strategies', 'distractions', 'people', 'professionals', 'environment_steps'] as $field) {
        expect($plan->$field)->not->toBeEmpty();
    }
});

test('test_demo_account_has_streak_of_14', function () {
    Sanctum::actingAs($this->demoUser);
    $this->getJson('/api/v1/dashboard')->assertOk()->assertJsonPath('data.streak_days', 14)
        ->assertJsonCount(7, 'data.weekly_moods')->assertJsonCount(5, 'data.recent_entries');
});

test('test_demo_account_cannot_be_deleted', function () {
    Http::preventStrayRequests();
    Sanctum::actingAs($this->demoUser);
    $this->deleteJson('/api/v1/account')->assertForbidden()->assertJsonPath('message', 'This account cannot be deleted.');
    $this->assertDatabaseHas('users', ['id' => $this->demoUser->id, 'is_demo' => true]);
    $this->assertDatabaseCount('mood_entries', 30);
    Http::assertNothingSent();
});

test('test_demo_account_email_cannot_change', function () {
    Sanctum::actingAs($this->demoUser);
    $this->patchJson('/api/v1/me', ['email' => 'changed@example.com'])->assertUnprocessable()->assertJsonValidationErrors('email');
    $this->patchJson('/api/v1/me', ['name' => 'Reviewer edit', 'email' => DemoAccountSeeder::EMAIL])
        ->assertOk()->assertJsonPath('data.name', 'Reviewer edit');
    $this->patchJson('/api/v1/user/profile', ['bio' => 'Trying out the profile editor.'])->assertOk();
    $profile = $this->demoUser->profile;
    $this->postJson('/api/v1/sync/batch', ['operations' => [[
        'type' => 'user_profile', 'action' => 'upsert', 'data' => ['id' => $profile->id, 'email' => 'changed@example.com'],
    ]]])->assertOk()->assertJsonPath('data.results.0.success', false);
    expect($this->demoUser->refresh()->email)->toBe(DemoAccountSeeder::EMAIL);
    expect($profile->refresh()->email)->toBe(DemoAccountSeeder::EMAIL);
});

test('test_demo_content_has_no_crisis_flags', function () {
    foreach ([MoodEntry::class, JournalEntry::class, ChatMessage::class, QuizAttempt::class] as $model) {
        expect($model::where('is_crisis_flagged', true)->count())->toBe(0);
    }
    $content = JournalEntry::pluck('body')->concat(ChatMessage::pluck('content'))->implode(' ');
    expect(preg_match('/suicid|self[ -]?harm|kill myself|end my life|hurt myself/i', $content))->toBe(0);
});

test('test_demo_account_is_isolated_from_real_users', function () {
    $real = User::factory()->create();
    Sanctum::actingAs($real);
    foreach (['mood-entries', 'journal-entries', 'conversations', 'quiz-attempts', 'notifications'] as $path) {
        $this->getJson("/api/v1/$path")->assertOk()->assertJsonCount(0, 'data.data');
    }
    $this->getJson('/api/v1/safety-plan')->assertNotFound();
    $this->getJson('/api/v1/dashboard')->assertOk()->assertJsonPath('data.streak_days', 0);
    $this->getJson('/api/v1/user/profile')->assertOk()->assertJsonPath('data.preferences', null);
    $this->getJson('/api/v1/subscription')->assertOk()->assertJsonPath('data.status', 'none');
    expect($real->bypassesPaywall())->toBeFalse();
    expect($real->bypassesTwoFactorAuthentication())->toBeFalse();
    $conversation = ChatConversation::withoutGlobalScopes()->where('user_id', $this->demoUser->id)->first();
    $this->getJson("/api/v1/conversations/{$conversation->id}")->assertNotFound();
    $this->getJson("/api/v1/conversations/{$conversation->id}/messages")->assertNotFound();
    $this->postJson('/api/v1/sync/batch', ['operations' => [[
        'type' => 'chat_message', 'action' => 'upsert', 'data' => [
            'id' => (string) Str::uuid(), 'conversation_id' => $conversation->id, 'role' => 'user', 'content' => 'Hello',
        ],
    ]]])->assertOk()->assertJsonPath('data.results.0.success', false);
    expect(ChatMessage::withoutGlobalScopes()->where('user_id', $real->id)->count())->toBe(0);
    $mood = MoodEntry::withoutGlobalScopes()->where('user_id', $this->demoUser->id)->first();
    $this->postJson('/api/v1/sync/batch', ['operations' => [[
        'type' => 'mood_entry', 'action' => 'delete', 'data' => ['id' => $mood->id, 'user_id' => $this->demoUser->id],
    ]]])->assertOk();
    expect(MoodEntry::withoutGlobalScopes()->where('user_id', $this->demoUser->id)->count())->toBe(30);
});

test('demo seeder is idempotent and does not touch real users even when authenticated', function () {
    $real = User::factory()->create();
    $realMood = MoodEntry::withoutGlobalScopes()->create(['id' => (string) Str::uuid(), 'user_id' => $real->id, 'mood' => 'good', 'intensity' => 7]);
    Sanctum::actingAs($real);
    $this->seed(DemoAccountSeeder::class);
    $this->seed(DemoAccountSeeder::class);
    expect(User::count())->toBe(2);
    expect(MoodEntry::withoutGlobalScopes()->where('user_id', $this->demoUser->id)->count())->toBe(30);
    expect(ChatMessage::withoutGlobalScopes()->where('user_id', $this->demoUser->id)->count())->toBe(10);
    expect($realMood->refresh()->intensity)->toBe(7);
    expect(UserProfile::withoutGlobalScopes()->where('user_id', $real->id)->count())->toBe(0);
});

test('demo seeder refuses a real account using the reserved address', function () {
    // Simulate an existing real account without deleting a protected demo user.
    DB::table('users')->where('id', $this->demoUser->id)->update(['is_demo' => false, 'name' => 'Real account']);
    expect(fn () => $this->seed(DemoAccountSeeder::class))->toThrow(RuntimeException::class);
    expect($this->demoUser->refresh()->name)->toBe('Real account');
    expect($this->demoUser->is_demo)->toBeFalse();
});

test('standard seeding does not add demo users in production', function () {
    DB::table('users')->where('id', $this->demoUser->id)->delete();
    $this->app->instance('env', 'production');
    $this->artisan('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true])->assertExitCode(0);
    expect(User::where('email', DemoAccountSeeder::EMAIL)->exists())->toBeFalse();
});

test('standard seeding creates the demo account in staging', function () {
    $this->app->instance('env', 'staging');
    $this->seed(DatabaseSeeder::class);
    expect(User::where('email', DemoAccountSeeder::EMAIL)->count())->toBe(1);
    expect(MoodEntry::count())->toBe(30);
});

test('conversation transcripts require authentication', function () {
    $conversation = ChatConversation::first();
    $this->getJson("/api/v1/conversations/{$conversation->id}/messages")->assertUnauthorized();
});

test('transcript reading and message sync also work for an ordinary users own conversation', function () {
    $real = User::factory()->create();
    Sanctum::actingAs($real);
    $conversation = ChatConversation::create(['id' => (string) Str::uuid(), 'user_id' => $real->id, 'title' => 'My conversation']);
    $this->getJson("/api/v1/conversations/{$conversation->id}/messages")->assertOk()->assertJsonCount(0, 'data.data');
    $messageId = (string) Str::uuid();
    $this->postJson('/api/v1/sync/batch', ['operations' => [[
        'type' => 'chat_message', 'action' => 'upsert', 'data' => [
            'id' => $messageId, 'conversation_id' => $conversation->id, 'role' => 'user', 'content' => 'Hello',
        ],
    ]]])->assertOk()->assertJsonPath('data.results.0.success', true);
    $this->getJson("/api/v1/conversations/{$conversation->id}/messages")->assertOk()
        ->assertJsonCount(1, 'data.data')->assertJsonPath('data.data.0.id', $messageId);
    $this->getJson("/api/v1/conversations/{$conversation->id}/messages?per_page=1000")->assertUnprocessable();
});
