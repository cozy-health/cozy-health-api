<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\SavedArticle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class Phase16CoreEndpointsTest extends TestCase
{
    use RefreshDatabase;

    private function notification(User $user, bool $read = false): AppNotification
    {
        return AppNotification::create([
            'id' => (string) Str::uuid(), 'user_id' => $user->id,
            'type' => 'reminder', 'title' => 'Reminder', 'body' => 'Check in',
            'is_read' => $read, 'client_created_at' => now(),
        ]);
    }

    private function savedArticle(User $user, string $slug): SavedArticle
    {
        return SavedArticle::create([
            'id' => (string) Str::uuid(), 'user_id' => $user->id,
            'article_id' => $slug, 'article_slug' => $slug,
            'title' => 'Saved article', 'category' => 'wellbeing',
            'read_time_minutes' => 5, 'saved_at' => now(),
            'client_created_at' => now(),
        ]);
    }

    public function test_unread_count_is_scoped_and_excludes_read_and_deleted_records(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $this->notification($user);
        $this->notification($user, true);
        $this->notification($user)->delete();
        $this->notification($other);

        $this->withToken($user->createToken('device')->plainTextToken)
            ->getJson('/api/v1/notifications/unread-count')
            ->assertOk()->assertExactJson(['data' => ['unread_count' => 1]]);

        $this->patchJson('/api/v1/notifications/read-all')->assertOk();
        $this->getJson('/api/v1/notifications/unread-count')
            ->assertExactJson(['data' => ['unread_count' => 0]]);
        $this->assertDatabaseHas('app_notifications', ['user_id' => $other->id, 'is_read' => false]);
    }

    public function test_saved_content_preserves_paginated_full_records_and_account_isolation(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $saved = $this->savedArticle($user, 'mine');
        $this->savedArticle($user, 'deleted')->delete();
        $this->savedArticle($other, 'other');

        $this->withToken($user->createToken('device')->plainTextToken)
            ->getJson('/api/v1/content/saved?per_page=1')
            ->assertOk()->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.id', $saved->id)
            ->assertJsonPath('data.data.0.article_slug', 'mine')
            ->assertJsonPath('data.data.0.read_time_minutes', 5)
            ->assertJsonStructure(['data' => ['data' => [['saved_at', 'client_created_at', 'title', 'category']]]]);
        $this->getJson('/api/v1/content/saved?per_page=0')->assertUnprocessable();
    }

    public function test_core_reads_require_authentication(): void
    {
        $this->getJson('/api/v1/content/saved')->assertUnauthorized();
        $this->getJson('/api/v1/notifications/unread-count')->assertUnauthorized();
    }

    public function test_empty_accounts_return_empty_results(): void
    {
        $user = User::factory()->create();
        $this->withToken($user->createToken('device')->plainTextToken)
            ->getJson('/api/v1/content/saved')->assertOk()->assertJsonPath('data.total', 0);
        $this->getJson('/api/v1/notifications/unread-count')
            ->assertOk()->assertExactJson(['data' => ['unread_count' => 0]]);
    }
}
