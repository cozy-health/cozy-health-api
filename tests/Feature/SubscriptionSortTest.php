<?php

use App\Http\Controllers\Api\V1\SubscriptionController;
use App\Models\SubscriptionStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('subscription_index_sorts_by_updated_at', function () {
    // Production exposes show(), not index(). This route exists only in this test.
    Route::get('/_test/subscriptions', [SubscriptionController::class, 'index']);

    $subscriptions = collect([2, 3, 1])->map(function ($daysAgo) {
        // The schema allows only one subscription status per user.
        $subscription = new SubscriptionStatus([
            'id' => (string) Str::uuid(),
            'user_id' => User::factory()->create()->id,
        ]);
        $subscription->created_at = now()->subDays(10);
        $subscription->updated_at = now()->subDays($daysAgo);
        $subscription->save();

        return $subscription;
    });

    $response = $this->getJson('/_test/subscriptions')->assertOk();

    expect(array_column($response->json('data.data'), 'id'))->toBe([
        $subscriptions[2]->id,
        $subscriptions[0]->id,
        $subscriptions[1]->id,
    ]);
});
