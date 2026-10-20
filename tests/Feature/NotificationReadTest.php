<?php

use App\Models\AppNotification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function createAppNotification(User $user, array $attributes = []): AppNotification
{
    return AppNotification::create(array_merge([
        'id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'type' => 'reminder',
        'title' => 'Check in',
        'body' => 'Time for a quick check-in.',
        'deep_link' => null,
        'data' => [],
        'is_read' => false,
        'read_at' => null,
        'client_created_at' => now(),
    ], $attributes));
}

it('marks a single notification as read', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $notification = createAppNotification($user);

    $this->patchJson("/api/v1/notifications/{$notification->id}/read")
        ->assertOk()
        ->assertJsonPath('message', 'Notification marked as read');

    $this->assertDatabaseHas('app_notifications', [
        'id' => $notification->id,
        'user_id' => $user->id,
        'is_read' => true,
    ]);

    expect($notification->fresh()->read_at)->not->toBeNull();
});

it('returns 404 when marking another users notification as read', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    Sanctum::actingAs($user);

    $notification = createAppNotification($otherUser);

    $this->patchJson("/api/v1/notifications/{$notification->id}/read")
        ->assertNotFound();

    $this->assertDatabaseHas('app_notifications', [
        'id' => $notification->id,
        'user_id' => $otherUser->id,
        'is_read' => false,
        'read_at' => null,
    ]);
});

it('marks all notifications as read for the authenticated user', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    Sanctum::actingAs($user);

    $first = createAppNotification($user);
    $second = createAppNotification($user);
    $other = createAppNotification($otherUser);

    $this->patchJson('/api/v1/notifications/read-all')
        ->assertOk()
        ->assertJsonPath('message', 'All notifications marked as read');

    expect($first->fresh()->is_read)->toBeTrue();
    expect($first->fresh()->read_at)->not->toBeNull();
    expect($second->fresh()->is_read)->toBeTrue();
    expect($second->fresh()->read_at)->not->toBeNull();
    expect($other->fresh()->is_read)->toBeFalse();
    expect($other->fresh()->read_at)->toBeNull();
});

it('returns 401 for unauthenticated requests', function () {
    $this->patchJson('/api/v1/notifications/read-all')
        ->assertUnauthorized();
});
