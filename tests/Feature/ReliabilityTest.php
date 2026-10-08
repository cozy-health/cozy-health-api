<?php

use App\Jobs\SendTestEmail;
use App\Models\User;
use App\Models\MoodEntry;
use App\Services\SlowQueryLogger;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('reports database cache and queue health', function () {
    $this->getJson('/up')->assertOk()->assertJsonPath('status', 'ok')->assertJsonPath('db', 'ok')->assertJsonPath('cache', 'ok');
});

it('rejects ordinary users and moderators at admin endpoints', function (string $role) {
    $user = User::factory()->create(); $user->forceFill(['role' => $role])->save();
    Sanctum::actingAs($user);
    $this->postJson('/api/v1/admin/queue/test-email', ['email' => 'test@example.org'])->assertForbidden();
})->with(['user', 'moderator']);

it('queues an administrator test email on the email queue', function () {
    Queue::fake(); $user = User::factory()->create(); $user->forceFill(['role' => 'admin'])->save();
    Sanctum::actingAs($user);
    $this->postJson('/api/v1/admin/queue/test-email', ['email' => 'test@example.org'])->assertStatus(202);
    Queue::assertPushedOn('email', SendTestEmail::class);
});

it('assigns an admin only through the explicit command', function () {
    $user = User::factory()->create();
    $this->artisan('user:make-admin', ['email' => $user->email])->assertSuccessful();
    expect($user->fresh()->role)->toBe('admin');
});

it('sanitizes and truncates slow queries without their binding values', function () {
    $query = new QueryExecuted("select * from journals where body = 'private journal' and note = ? ".str_repeat('x', 3000), ['secret note', 12], 501, DB::connection());
    $context = app(SlowQueryLogger::class)->context($query);
    expect(strlen($context['sql']))->toBe(2000);
    expect($context['binding_types'])->toBe(['string', 'int']);
    expect(json_encode($context))->not->toContain('private journal')->not->toContain('secret note');
});

it('soft deletes ordinary records but permanently erases an account including soft deleted rows', function () {
    $user = User::factory()->create();
    $entry = MoodEntry::create(['id' => (string) Str::uuid(), 'user_id' => $user->id, 'mood' => 'good', 'intensity' => 5]);
    $entry->delete(); $this->assertSoftDeleted('mood_entries', ['id' => $entry->id]);
    expect(MoodEntry::find($entry->id))->toBeNull();
    Sanctum::actingAs($user);
    $this->deleteJson('/api/v1/account')->assertOk();
    $this->assertDatabaseMissing('users', ['id' => $user->id]);
    $this->assertDatabaseMissing('mood_entries', ['id' => $entry->id]);
});

it('rejects invalid mood fields and pagination', function () {
    Sanctum::actingAs(User::factory()->create());
    $this->postJson('/api/v1/mood-entries', ['id' => 'invalid'])->assertUnprocessable();
    $this->getJson('/api/v1/mood-entries?per_page=100000')->assertUnprocessable();
});
