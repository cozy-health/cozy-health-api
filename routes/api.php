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
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\InsightsController;
use App\Http\Controllers\Api\V1\AffirmationController;
use App\Http\Controllers\Api\V1\PasswordResetController;
use App\Http\Controllers\Api\V1\ProviderController;

Route::prefix('v1')->group(function () {
    Route::post('auth/register', [AuthController::class, 'register'])
        ->middleware('throttle:5,1');
    Route::post('auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:10,1');
    Route::post('auth/google', [AuthController::class, 'googleSignIn'])
        ->middleware('throttle:10,1');
    Route::post('auth/apple', [AuthController::class, 'appleSignIn'])
        ->middleware('throttle:10,1');
    Route::post('auth/forgot-password', [PasswordResetController::class, 'forgotPassword'])
        ->middleware('throttle:3,1');
    Route::post('auth/reset-password', [PasswordResetController::class, 'resetPassword'])
        ->middleware('throttle:5,1');
});

Route::middleware('auth:sanctum')->prefix('v1')->group(function () {
    Route::get('dashboard', [DashboardController::class, 'show'])->middleware('throttle:60,1');
    Route::get('/affirmations/today', [AffirmationController::class, 'today'])->middleware('throttle:60,1');
    Route::prefix('/insights')->group(function () {
        Route::get('/weekly', [InsightsController::class, 'weekly'])->middleware('throttle:60,1');
        Route::get('/triggers', [InsightsController::class, 'triggers'])->middleware('throttle:60,1');
        Route::get('/sleep-mood', [InsightsController::class, 'sleepMood'])->middleware('throttle:60,1');
    });
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::delete('account', [AccountController::class, 'destroy'])->middleware('throttle:5,1');
    Route::get('me', [\App\Http\Controllers\Api\V1\ProfileController::class, 'show']);
    Route::patch('me', [\App\Http\Controllers\Api\V1\ProfileController::class, 'update']);
    Route::apiResource('mood-entries', MoodEntryController::class);
    Route::apiResource('journal-entries', JournalEntryController::class);
    Route::get('journals', [JournalEntryController::class, 'index']);
    Route::apiResource('conversations', ChatController::class);
    Route::get('safety-plan', [SafetyPlanController::class, 'show']);
    Route::put('safety-plan', [SafetyPlanController::class, 'store']);
    Route::get('user/profile', [UserProfileController::class, 'show']);
    Route::patch('user/profile', [UserProfileController::class, 'update']);
    Route::patch('user/preferences', [UserProfileController::class, 'updatePreferences']);
    Route::patch('user/notification-preferences', [UserProfileController::class, 'updateNotificationPreferences']);
    Route::get('provider', [ProviderController::class, 'show']);
    Route::post('provider/request', [ProviderController::class, 'requestCode']);
    Route::post('provider/verify', [ProviderController::class, 'verify']);
    Route::delete('provider', [ProviderController::class, 'destroy']);
    Route::apiResource('quiz-attempts', QuizController::class);
    Route::get('quiz-results', [QuizController::class, 'index']);
    Route::patch('notifications/read-all', [NotificationController::class, 'markAllAsRead']);
    Route::patch('notifications/{id}/read', [NotificationController::class, 'markAsRead']);
    Route::apiResource('notifications', NotificationController::class);
    Route::get('subscription', [SubscriptionController::class, 'show']);
    Route::post('sync/batch', [SyncController::class, 'batch']);
});
