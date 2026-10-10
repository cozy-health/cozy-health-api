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
    Route::post('auth/refresh', [\App\Http\Controllers\AuthSessionController::class, 'refresh'])->middleware('throttle:30,1');
    Route::get('admin/health/queue-depth', \App\Http\Controllers\AdminQueueHealthController::class)
        ->middleware(['auth:sanctum', 'role:admin', 'throttle:30,1']);
    Route::post('admin/auth/login', [\App\Http\Controllers\AdminAuthController::class, 'login'])->middleware(['throttle:10,1', \App\Http\Middleware\LoginLockout::class]);
    Route::get('admin/metrics/{metric}', [\App\Http\Controllers\AdminMetricsController::class, 'show'])
        ->where('metric', 'overview|usage|retention|safety')->middleware(['auth:sanctum', 'role:admin', 'admin.analytics', 'throttle:30,1']);
    Route::middleware(['auth:sanctum', 'role:admin', 'admin.analytics', 'throttle:30,1'])->prefix('admin/auth')->group(function () {
        Route::get('session', [\App\Http\Controllers\AdminAuthController::class, 'session']);
        Route::post('logout', [\App\Http\Controllers\AdminAuthController::class, 'logout']);
    });
    Route::get('admin/metrics/analytics', \App\Http\Controllers\AnalyticsMetricsController::class)
        ->middleware(['auth:sanctum', 'role:admin', 'admin.analytics', 'throttle:30,1']);
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
        ->middleware(['throttle:10,1', \App\Http\Middleware\LoginLockout::class]);
    Route::post('auth/google', [AuthController::class, 'googleSignIn'])->middleware('throttle:10,1');
    Route::post('auth/apple', [AuthController::class, 'appleSignIn'])->middleware('throttle:10,1');
    Route::post('auth/forgot-password', [PasswordResetController::class, 'forgotPassword'])
        ->middleware(['throttle:5,1', 'throttle:password-reset-email']);
    Route::post('auth/reset-password', [PasswordResetController::class, 'resetPassword'])
        ->middleware('throttle:5,1');
});

Route::middleware(['auth:sanctum', \App\Http\Middleware\EnsurePatient::class])->prefix('v1')->group(function () {
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
    $sessions = \App\Http\Controllers\AuthSessionController::class;
    Route::post('auth/revoke-refresh', [$sessions, 'revokeRefresh'])->middleware('throttle:30,1');
    Route::post('auth/logout-all', [$sessions, 'destroyAll'])->middleware('throttle:30,1');
    Route::get('me/sessions', [$sessions, 'index'])->middleware('throttle:30,1');
    Route::delete('me/sessions/{id}', [$sessions, 'destroy'])->middleware('throttle:30,1');
    Route::delete('me/sessions', [$sessions, 'destroyAll'])->middleware('throttle:30,1');
    Route::post('me/password', [$sessions, 'password'])->middleware('throttle:5,1');
    Route::post('me/device-security', \App\Http\Controllers\DeviceSecurityController::class)->middleware('throttle:10,60');
    Route::delete('account', [AccountController::class, 'destroy'])->middleware('throttle:5,1');
    Route::get('me', [\App\Http\Controllers\Api\V1\ProfileController::class, 'show']);
    Route::get('me/consents', [\App\Http\Controllers\DocumentConsentController::class, 'index'])->middleware('throttle:60,1');
    Route::post('me/consents', [\App\Http\Controllers\DocumentConsentController::class, 'store'])->middleware('throttle:30,1');
    Route::get('me/provider/links', [\App\Http\Controllers\Api\V1\Provider\PatientConsentController::class, 'index']);
    Route::get('me/provider-access-log', [\App\Http\Controllers\Api\V1\Provider\ProviderAccessLogController::class, 'patient']);
    Route::get('me/resources', [\App\Http\Controllers\Api\V1\Provider\ProviderAssignmentController::class, 'patientIndex']);
    Route::patch('me/resources/{assignmentId}', [\App\Http\Controllers\Api\V1\Provider\ProviderAssignmentController::class, 'patientUpdate']);
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
    Route::get('content/saved', [ContentController::class, 'index'])->middleware('throttle:60,1');
    Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount'])->middleware('throttle:60,1');
    Route::patch('notifications/read-all', [NotificationController::class, 'markAllAsRead']);
    Route::patch('notifications/{id}/read', [NotificationController::class, 'markAsRead']);
    Route::apiResource('notifications', NotificationController::class);
    Route::get('subscription', [SubscriptionController::class, 'show']);
    Route::post('sync/batch', [SyncController::class, 'batch'])->middleware('throttle:60,1');
});


// Provider accounts use their own model-scoped Sanctum guard. Patient linking routes above are unchanged.
Route::prefix('v1/provider')->group(function () {
    Route::post('auth/refresh', [\App\Http\Controllers\AuthSessionController::class, 'refresh'])->middleware('throttle:30,1');
    $controller = \App\Http\Controllers\Api\V1\Provider\ProviderAuthController::class;
    Route::post('auth/register', [$controller, 'register'])->middleware('throttle:5,1');
    Route::post('auth/login', [$controller, 'login'])->middleware(['throttle:10,1', \App\Http\Middleware\LoginLockout::class]);
    Route::post('auth/forgot-password', [$controller, 'forgotPassword'])->middleware(['throttle:5,1', 'throttle:provider-password-reset-email']);
    Route::post('auth/reset-password', [$controller, 'resetPassword'])->middleware('throttle:5,1');
    Route::middleware(['auth:provider', \App\Http\Middleware\EnsureActiveProvider::class])->group(function () use ($controller) {
        Route::post('auth/logout', [$controller, 'logout']);
        Route::post('auth/revoke-refresh', [\App\Http\Controllers\AuthSessionController::class, 'revokeRefresh'])->middleware('throttle:30,1');
        Route::get('me', [$controller, 'me']);
        Route::get('consent-config', [\App\Http\Controllers\DocumentConsentController::class, 'configuration'])->middleware('throttle:60,1');
        Route::get('me/consents', [\App\Http\Controllers\DocumentConsentController::class, 'index'])->middleware('throttle:60,1');
        Route::post('me/consents', [\App\Http\Controllers\DocumentConsentController::class, 'store'])->middleware('throttle:30,1');
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
        Route::post('clients/{userId}/notes', [$notes, 'store'])->middleware('throttle:30,1');
        Route::delete('clients/{userId}/notes/{noteId}', [$notes, 'destroy']);
        Route::get('access-log', [\App\Http\Controllers\Api\V1\Provider\ProviderAccessLogController::class, 'provider']);
        $resources = \App\Http\Controllers\Api\V1\Provider\ProviderResourceController::class;
        Route::get('resources', [$resources, 'index']);
        Route::post('resources', [$resources, 'store']);
        Route::patch('resources/{resourceId}', [$resources, 'update']);
        Route::delete('resources/{resourceId}', [$resources, 'destroy']);
        $assignments = \App\Http\Controllers\Api\V1\Provider\ProviderAssignmentController::class;
        Route::get('clients/{userId}/resources', [$assignments, 'index']);
        Route::post('clients/{userId}/resources', [$assignments, 'store'])->middleware('throttle:30,1');
        Route::delete('clients/{userId}/resources/{assignmentId}', [$assignments, 'destroy']);
        $reports = \App\Http\Controllers\Api\V1\Provider\ProviderReportController::class;
        Route::get('reports', [$reports, 'index']);
        Route::post('reports', [$reports, 'store']);
        Route::get('reports/{reportId}', [$reports, 'show']);
        Route::get('reports/{reportId}/download', [$reports, 'download']);
        Route::get('reports/{reportId}/file', [$reports, 'file'])->middleware('signed:relative')->name('provider.reports.file');
    });
});
