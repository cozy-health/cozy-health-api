<?php

use App\Http\Controllers\Api\V1\SafetyPlanController;
use App\Models\SafetyPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('safety_plan_index_sorts_by_updated_at', function () {
    // Production exposes show(), not index(). This route exists only in this test.
    Route::get('/_test/safety-plans', [SafetyPlanController::class, 'index']);

    $plans = collect([2, 3, 1])->map(function ($daysAgo) {
        // The schema allows only one safety plan per user.
        $plan = new SafetyPlan([
            'id' => (string) Str::uuid(),
            'user_id' => User::factory()->create()->id,
        ]);
        $plan->created_at = now()->subDays(10);
        $plan->updated_at = now()->subDays($daysAgo);
        $plan->save();

        return $plan;
    });

    $response = $this->getJson('/_test/safety-plans')->assertOk();

    expect(array_column($response->json('data.data'), 'id'))->toBe([
        $plans[2]->id,
        $plans[0]->id,
        $plans[1]->id,
    ]);
});
