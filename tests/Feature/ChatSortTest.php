<?php

use App\Models\ChatConversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

test('conversation_list_sorts_by_updated_at', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $conversations = collect([2, 3, 1])->map(function ($daysAgo) use ($user) {
        $conversation = new ChatConversation([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'title' => "Conversation {$daysAgo}",
        ]);
        $conversation->created_at = now()->subDays(10);
        $conversation->updated_at = now()->subDays($daysAgo);
        $conversation->save();

        return $conversation;
    });

    $response = $this->getJson('/api/v1/conversations')->assertOk();

    expect(array_column($response->json('data.data'), 'id'))->toBe([
        $conversations[2]->id,
        $conversations[0]->id,
        $conversations[1]->id,
    ]);
});
