<?php

use App\Models\JournalEntry;
use App\Models\QuizAttempt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('returns the same records and pagination shape as the primary', function (string $primary, string $alias, string $model) {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $ids = [];
    foreach ([$user, $user, $other] as $index => $owner) {
        $attributes = $model === JournalEntry::class
            ? ['type' => 'free', 'body' => 'Journal '.$index, 'is_draft' => $index === 0]
            : ['quiz_slug' => 'wellbeing', 'quiz_title' => 'Wellbeing', 'answers' => [1, 2], 'score' => 3,
                'completed_at' => '2026-10-07 10:00:00'];
        $entry = $model::withoutGlobalScopes()->create([
            'id' => (string) Str::uuid(), 'user_id' => $owner->id,
            'client_created_at' => '2026-10-07 10:0'.$index.':00', ...$attributes,
        ]);
        if ($owner->id === $user->id) {
            $ids[] = $entry->id;
        }
    }
    Sanctum::actingAs($user);

    foreach ([1, 2] as $page) {
        $query = '?per_page=1&page='.$page;
        $expected = $this->getJson('/api/v1/'.$primary.$query)->assertOk()->json();
        $actual = $this->getJson('/api/v1/'.$alias.$query)->assertOk()->assertJsonCount(1, 'data.data')->json();
        expect(array_keys($actual))->toBe(array_keys($expected));
        expect(array_keys($actual['data']))->toBe(array_keys($expected['data']));
        expect($actual['data']['data'])->toBe($expected['data']['data']);
        foreach (['current_page', 'last_page', 'per_page', 'total', 'from', 'to'] as $key) {
            expect($actual['data'][$key])->toBe($expected['data'][$key]);
        }
        expect($actual['data']['total'])->toBe(2);
        expect($actual['data']['data'][0]['id'])->toBe($ids[2 - $page]);
        // Pagination URLs legitimately contain the route that was requested.
        expect($actual['data']['path'])->toEndWith('/api/v1/'.$alias);
    }
})->with([
    'journals' => ['journal-entries', 'journals', JournalEntry::class],
    'quiz results' => ['quiz-attempts', 'quiz-results', QuizAttempt::class],
]);

it('requires authentication for the fallback alias', function (string $alias) {
    $this->getJson('/api/v1/'.$alias)->assertUnauthorized();
})->with(['journals', 'quiz-results']);
