<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\MoodEntryController;
use App\Http\Controllers\Api\V1\JournalEntryController;
use App\Http\Controllers\Api\V1\ChatController;
use App\Http\Controllers\Api\V1\SafetyPlanController;
use App\Http\Controllers\Api\V1\UserProfileController;
use App\Http\Controllers\Api\V1\QuizController;
use App\Http\Controllers\Api\V1\ContentController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\SubscriptionController;
use App\Http\Controllers\Api\V1\SyncController;

Route::middleware('auth:sanctum')->prefix('v1')->group(function () {
    Route::apiResource('mood-entries', MoodEntryController::class);
    Route::apiResource('journal-entries', JournalEntryController::class);
    Route::apiResource('conversations', ChatController::class);
    Route::get('safety-plan', [SafetyPlanController::class, 'show']);
    Route::put('safety-plan', [SafetyPlanController::class, 'store']);
    Route::get('user/profile', [UserProfileController::class, 'show']);
    Route::patch('user/profile', [UserProfileController::class, 'update']);
    Route::patch('user/preferences', [UserProfileController::class, 'updatePreferences']);
    Route::patch('user/notification-preferences', [UserProfileController::class, 'updateNotificationPreferences']);
    Route::apiResource('quiz-attempts', QuizController::class);
    Route::apiResource('notifications', NotificationController::class);
    Route::get('subscription', [SubscriptionController::class, 'show']);
    Route::post('sync/batch', [SyncController::class, 'batch']);
});