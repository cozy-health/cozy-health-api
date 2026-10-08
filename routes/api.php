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
    Route::get('admin/metrics/analytics', \App\Http\Controllers\AnalyticsMetricsController::class)
        ->middleware(['auth:sanctum', 'role:admin', 'throttle:30,1']);
    Route::put('admin/settings/{key}', [\App\Http\Controllers\AdminSettingsController::class, 'update'])
        ->middleware(['auth:sanctum', 'role:admin', 'throttle:30,1']);
    Route::get('config', [\App\Http\Controllers\Api\V1\ConfigController::class, 'show'])->middleware('throttle:120,1');
    Route::post('admin/queue/test-email', function (\App\Http\Requests\TestEmailRequest $request) {
        \App\Jobs\SendTestEmail::dispatch($request->validated('email'));
        return response()->json(['message' => 'Test email queued.'], 202);
    })->middleware(['auth:sanctum', 'role:admin', 'throttle:3,60']);
    Route::post('auth/register', [AuthController::class, 'register'])
        ->middleware('throttle:5,1');
    Route::post('auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:10,1');
    Route::post('auth/google', [AuthController::class, 'googleSignIn'])
        ->middleware('throttle:10,1');
    Route::post('auth/apple', [AuthController::class, 'appleSignIn'])
        ->middleware('throttle:10,1');
    Route::post('auth/forgot-password', [PasswordResetController::class, 'forgotPassword'])
        ->middleware(['throttle:3,1', 'throttle:password-reset-email']);
    Route::post('auth/reset-password', [PasswordResetController::class, 'resetPassword'])
        ->middleware('throttle:5,1');
});

Route::middleware('auth:sanctum')->prefix('v1')->group(function () {
    Route::post('auth/email-verification/resend', [\App\Http\Controllers\Api\V1\EmailVerificationController::class, 'resend'])->middleware('throttle:verification-email');
    Route::post('auth/email-verification/verify', [\App\Http\Controllers\Api\V1\EmailVerificationController::class, 'verify'])->middleware('throttle:5,1');
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
    Route::get('me/provider/links', [\App\Http\Controllers\Api\V1\Provider\PatientConsentController::class, 'index']);
    Route::get('me/provider-access-log', [\App\Http\Controllers\Api\V1\Provider\ProviderAccessLogController::class, 'patient']);
    Route::patch('me/provider/{linkId}/consent', [\App\Http\Controllers\Api\V1\Provider\PatientConsentController::class, 'update']);
    Route::delete('me/provider/{linkId}', [\App\Http\Controllers\Api\V1\Provider\PatientConsentController::class, 'destroy']);
    Route::patch('me', [\App\Http\Controllers\Api\V1\ProfileController::class, 'update']);
    Route::apiResource('mood-entries', MoodEntryController::class);
    Route::apiResource('journal-entries', JournalEntryController::class);
    Route::get('journals', [JournalEntryController::class, 'index']);
    Route::apiResource('conversations', ChatController::class);
    Route::get('conversations/{id}/messages', [ChatController::class, 'messages'])->middleware('throttle:60,1');
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


// Provider accounts use their own model-scoped Sanctum guard. Patient linking routes above are unchanged.
Route::prefix('v1/provider')->group(function () {
    $controller = \App\Http\Controllers\Api\V1\Provider\ProviderAuthController::class;
    Route::post('auth/register', [$controller, 'register'])->middleware('throttle:5,1');
    Route::post('auth/login', [$controller, 'login'])->middleware('throttle:10,1');
    Route::post('auth/forgot-password', [$controller, 'forgotPassword'])->middleware(['throttle:3,1', 'throttle:provider-password-reset-email']);
    Route::post('auth/reset-password', [$controller, 'resetPassword'])->middleware('throttle:5,1');
    Route::middleware(['auth:provider', \App\Http\Middleware\EnsureActiveProvider::class])->group(function () use ($controller) {
        Route::post('auth/logout', [$controller, 'logout']);
        Route::get('me', [$controller, 'me']);
        Route::patch('me', [$controller, 'update']);
        $clients = \App\Http\Controllers\Api\V1\Provider\ProviderClientController::class;
        Route::get('clients', [$clients, 'index']);
        Route::get('clients/{userId}', [$clients, 'show']);
        Route::get('clients/{userId}/moods', [$clients, 'moods']);
        Route::get('clients/{userId}/journals', [$clients, 'journals']);
        Route::get('clients/{userId}/quizzes', [$clients, 'quizzes']);
        Route::get('clients/{userId}/consents', [$clients, 'consents']);
        $notes = \App\Http\Controllers\Api\V1\Provider\ProviderNotesController::class;
        Route::get('clients/{userId}/notes', [$notes, 'index']);
        Route::post('clients/{userId}/notes', [$notes, 'store']);
        Route::delete('clients/{userId}/notes/{noteId}', [$notes, 'destroy']);
        Route::get('access-log', [\App\Http\Controllers\Api\V1\Provider\ProviderAccessLogController::class, 'provider']);
    });
});
