<?php

use App\Http\Controllers\AdminAuthController;
use App\Http\Controllers\AdminMetricsController;
use App\Http\Controllers\AdminQueueHealthController;
use App\Http\Controllers\AdminSettingsController;
use App\Http\Controllers\AnalyticsMetricsController;
use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\AffirmationController;
use App\Http\Controllers\Api\V1\ArticleController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ChatController;
use App\Http\Controllers\Api\V1\CommunityController;
use App\Http\Controllers\Api\V1\ConfigController;
use App\Http\Controllers\Api\V1\ContentController;
use App\Http\Controllers\Api\V1\CrisisResourceController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\EmailVerificationController;
use App\Http\Controllers\Api\V1\InsightsController;
use App\Http\Controllers\Api\V1\JournalEntryController;
use App\Http\Controllers\Api\V1\MoodEntryController;
use App\Http\Controllers\Api\V1\MoodStatsController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\PasswordResetController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\Provider\PatientConsentController;
use App\Http\Controllers\Api\V1\Provider\ProviderAccessLogController;
use App\Http\Controllers\Api\V1\Provider\ProviderAssignmentController;
use App\Http\Controllers\Api\V1\Provider\ProviderAuthController;
use App\Http\Controllers\Api\V1\Provider\ProviderClientController;
use App\Http\Controllers\Api\V1\Provider\ProviderNotesController;
use App\Http\Controllers\Api\V1\Provider\ProviderReportController;
use App\Http\Controllers\Api\V1\Provider\ProviderResourceController;
use App\Http\Controllers\Api\V1\ProviderController;
use App\Http\Controllers\Api\V1\PushDeviceController;
use App\Http\Controllers\Api\V1\QuizCatalogController;
use App\Http\Controllers\Api\V1\QuizController;
use App\Http\Controllers\Api\V1\SafetyPlanController;
use App\Http\Controllers\Api\V1\SubscriptionController;
use App\Http\Controllers\Api\V1\SyncController;
use App\Http\Controllers\Api\V1\UserProfileController;
use App\Http\Controllers\AuthSessionController;
use App\Http\Controllers\DeviceSecurityController;
use App\Http\Controllers\DocumentConsentController;
use App\Http\Middleware\CommunityReady;
use App\Http\Middleware\EnsureActiveProvider;
use App\Http\Middleware\EnsurePatient;
use App\Http\Middleware\LoginLockout;
use App\Http\Requests\TestEmailRequest;
use App\Jobs\SendTestEmail;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::middleware(['auth:sanctum', 'role:admin', 'throttle:60,1,admin-management-'])->prefix('admin')->group(function () {
        Route::get('operations/summary', \App\Http\Controllers\AdminOverviewController::class)->middleware('admin.manage:metrics');
        Route::middleware('admin.manage:content')->group(function () {
            Route::get('articles', [\App\Http\Controllers\AdminArticleController::class, 'index']);
            Route::post('articles', [\App\Http\Controllers\AdminArticleController::class, 'store']);
            Route::get('articles/{id}', [\App\Http\Controllers\AdminArticleController::class, 'show'])->whereUuid('id');
            Route::put('articles/{id}', [\App\Http\Controllers\AdminArticleController::class, 'update'])->whereUuid('id');
            Route::post('articles/{id}/publish', [\App\Http\Controllers\AdminArticleController::class, 'publish'])->whereUuid('id');
            Route::delete('articles/{id}', [\App\Http\Controllers\AdminArticleController::class, 'unpublish'])->whereUuid('id');
            Route::get('content/{resource}', [\App\Http\Controllers\AdminContentController::class, 'index'])->where('resource', 'moods|expressions|triggers|coping|communities|challenges|packages');
            Route::get('content/{resource}/{id}', [\App\Http\Controllers\AdminContentController::class, 'show'])->whereNumber('id')->where('resource', 'packages');
            Route::post('content/{resource}', [\App\Http\Controllers\AdminContentController::class, 'store'])->where('resource', 'moods|expressions|triggers|coping|communities|challenges|packages');
            Route::put('content/{resource}/{id}', [\App\Http\Controllers\AdminContentController::class, 'update'])->whereNumber('id')->where('resource', 'moods|expressions|triggers|coping|communities|challenges|packages');
            Route::delete('content/{resource}/{id}', [\App\Http\Controllers\AdminContentController::class, 'archive'])->whereNumber('id')->where('resource', 'moods|expressions|triggers|coping|communities|challenges|packages');
            Route::get('quizzes', [\App\Http\Controllers\AdminQuizController::class, 'index']);
            Route::post('quizzes', [\App\Http\Controllers\AdminQuizController::class, 'store']);
            Route::put('quizzes/{id}', [\App\Http\Controllers\AdminQuizController::class, 'update'])->whereNumber('id');
            Route::post('quizzes/{id}/publish', [\App\Http\Controllers\AdminQuizController::class, 'publish'])->whereNumber('id');
            Route::delete('quizzes/{id}', [\App\Http\Controllers\AdminQuizController::class, 'archive'])->whereNumber('id');
        });
        Route::middleware('admin.manage:users')->group(function () {
            Route::get('users', [\App\Http\Controllers\AdminUsersController::class, 'index']);
        });
        Route::middleware('admin.manage:moderation')->group(function () {
            Route::get('community/{kind}', [\App\Http\Controllers\AdminCommunityController::class, 'index'])->where('kind', 'posts|comments|members');
            Route::get('community/{kind}/{id}', [\App\Http\Controllers\AdminCommunityController::class, 'show'])->where('kind', 'posts|comments')->whereUuid('id');
            Route::put('community/{kind}/{id}', [\App\Http\Controllers\AdminCommunityController::class, 'update'])->where('kind', 'posts|comments')->whereUuid('id');
            Route::delete('community/members/{id}', [\App\Http\Controllers\AdminCommunityController::class, 'removeMember'])->whereNumber('id');
            Route::get('reports', [\App\Http\Controllers\AdminOperationsController::class, 'reports']);
            Route::get('reports/{id}', [\App\Http\Controllers\AdminOperationsController::class, 'reportDetail'])->whereNumber('id');
            Route::get('support/{id}', [\App\Http\Controllers\AdminOperationsController::class, 'supportDetail'])->whereNumber('id');
            Route::get('reports/{id}/target', [\App\Http\Controllers\AdminOperationsController::class, 'target'])->whereNumber('id');
            Route::put('reports/{id}', [\App\Http\Controllers\AdminOperationsController::class, 'review'])->whereNumber('id');
            Route::get('support', [\App\Http\Controllers\AdminOperationsController::class, 'support']);
            Route::put('support/{id}', [\App\Http\Controllers\AdminOperationsController::class, 'resolveSupport'])->whereNumber('id');
        });
        Route::get('student-applications', [\App\Http\Controllers\Api\V1\StudentVerificationController::class, 'index'])->middleware('admin.manage:subscriptions');
        Route::get('development-access', [\App\Http\Controllers\AdminDevelopmentAccessController::class, 'index'])->middleware('admin.manage:subscriptions');
        Route::post('development-access', [\App\Http\Controllers\AdminDevelopmentAccessController::class, 'store'])->middleware('admin.manage:subscriptions');
        Route::delete('development-access/{id}', [\App\Http\Controllers\AdminDevelopmentAccessController::class, 'destroy'])->whereUuid('id')->middleware('admin.manage:subscriptions');
        Route::get('student-applications/{id}/evidence', [\App\Http\Controllers\Api\V1\StudentVerificationController::class, 'evidence'])->whereUuid('id')->middleware('admin.manage:subscriptions');
        Route::post('student-applications/{id}/review', [\App\Http\Controllers\Api\V1\StudentVerificationController::class, 'review'])->whereUuid('id')->middleware('admin.manage:subscriptions');
        Route::get('subscriptions', [\App\Http\Controllers\AdminOperationsController::class, 'subscriptions'])->middleware('admin.manage:subscriptions');
        Route::get('subscriptions/history', [\App\Http\Controllers\AdminOperationsController::class, 'subscriptionHistory'])->middleware('admin.manage:subscriptions');
        Route::middleware('admin.manage:email')->group(function () {
            Route::get('emails', [\App\Http\Controllers\AdminEmailController::class, 'index']);
            Route::get('emails/{event}/preview', [\App\Http\Controllers\AdminEmailController::class, 'preview']);
            Route::post('emails/test', [\App\Http\Controllers\AdminEmailController::class, 'test'])->middleware('throttle:admin-email-test');
        });
    });
    Route::get('admin/metrics/insights', \App\Http\Controllers\AdminInsightsController::class)
        ->middleware(['auth:sanctum', 'role:admin', 'admin.analytics', 'throttle:30,1,admin-metrics-']);
    Route::post('notifications/cleanup', [PushDeviceController::class, 'cleanup'])->middleware('throttle:30,1');
    Route::post('auth/refresh', [AuthSessionController::class, 'refresh'])->middleware('throttle:30,1');
    Route::get('admin/health/queue-depth', AdminQueueHealthController::class)
        ->middleware(['auth:sanctum', 'role:admin', 'throttle:30,1']);
    Route::post('admin/auth/login', [AdminAuthController::class, 'login'])->middleware(['throttle:10,1', LoginLockout::class]);
    Route::get('admin/metrics/{metric}', [AdminMetricsController::class, 'show'])
        ->where('metric', 'overview|usage|retention|safety')->middleware(['auth:sanctum', 'role:admin', 'admin.analytics', 'throttle:30,1,admin-metrics-']);
    Route::middleware(['auth:sanctum', 'role:admin', 'admin.analytics', 'throttle:30,1,admin-session-'])->prefix('admin/auth')->group(function () {
        Route::get('session', [AdminAuthController::class, 'session']);
        Route::post('logout', [AdminAuthController::class, 'logout']);
    });
    Route::get('admin/metrics/analytics', AnalyticsMetricsController::class)
        ->middleware(['auth:sanctum', 'role:admin', 'admin.analytics', 'throttle:30,1,admin-metrics-']);
    Route::put('admin/settings/{key}', [AdminSettingsController::class, 'update'])
        ->middleware(['auth:sanctum', 'role:admin', 'throttle:30,1']);
    Route::get('config', [ConfigController::class, 'show'])->middleware('throttle:120,1');
    Route::post('admin/queue/test-email', function (TestEmailRequest $request) {
        SendTestEmail::dispatch($request->validated('email'));

        return response()->json(['message' => 'Test email queued.'], 202);
    })->middleware(['auth:sanctum', 'role:admin', 'throttle:3,60']);
    Route::post('auth/register', [AuthController::class, 'register'])
        ->middleware('throttle:5,1');
    Route::post('auth/login', [AuthController::class, 'login'])
        ->middleware(['throttle:10,1', LoginLockout::class]);
    Route::post('auth/google', [AuthController::class, 'googleSignIn'])->middleware('throttle:10,1');
    Route::post('auth/apple', [AuthController::class, 'appleSignIn'])->middleware('throttle:10,1');
    Route::post('auth/forgot-password', [PasswordResetController::class, 'forgotPassword'])
        ->middleware(['throttle:5,1', 'throttle:password-reset-email']);
    Route::post('auth/reset-password', [PasswordResetController::class, 'resetPassword'])
        ->middleware('throttle:5,1');
});

Route::middleware(['auth:sanctum', EnsurePatient::class, \App\Http\Middleware\EnforcePlanAccess::class])->prefix('v1')->group(function () {
    Route::get('me/plan-access', fn (\Illuminate\Http\Request $request) => response()->json(['data' => app(\App\Services\PlanAccess::class)->current($request->user())])->header('Cache-Control', 'private, no-store'));
    $retiredAge = fn () => response()->json(['message' => 'The custom age and guardian process has been retired.', 'code' => 'age_flow_retired'], 410);
    Route::get('me/eligibility', fn (\Illuminate\Http\Request $request) => response()->json(['data' => app(\App\Services\AgeEligibility::class)->state($request->user())]));
    Route::post('me/age', $retiredAge)->middleware('throttle:10,1');
    Route::put('me/residence', $retiredAge)->middleware('throttle:3,60');
    Route::post('me/guardian', $retiredAge)->middleware('throttle:10,1');
    Route::delete('me/guardian', $retiredAge)->middleware('throttle:10,1');
    Route::post('me/age-support', $retiredAge)->middleware('throttle:3,1440');
    Route::post('audio-attachments', [\App\Http\Controllers\Api\V1\AudioAttachmentController::class, 'store'])->middleware('throttle:20,1');
    Route::get('audio-attachments/{id}', [\App\Http\Controllers\Api\V1\AudioAttachmentController::class, 'show'])->whereUuid('id');

    Route::get('wellness/catalog', [\App\Http\Controllers\Api\V1\WellnessCatalogController::class, 'catalog'])->middleware('throttle:60,1');
    Route::get('wellness/achievements', [\App\Http\Controllers\Api\V1\WellnessCatalogController::class, 'achievements'])->middleware('throttle:30,1');
    Route::get('wellness/challenges', [\App\Http\Controllers\Api\V1\WellnessCatalogController::class, 'challenges'])->middleware('throttle:60,1');
    Route::post('wellness/challenges/{id}/enroll', [\App\Http\Controllers\Api\V1\WellnessCatalogController::class, 'enroll'])->whereNumber('id')->middleware('throttle:30,1');
    Route::post('wellness/challenges/{id}/complete', [\App\Http\Controllers\Api\V1\WellnessCatalogController::class, 'complete'])->whereNumber('id')->middleware('throttle:30,1');
    Route::post('auth/email-verification/resend', [EmailVerificationController::class, 'resend'])->middleware('throttle:verification-email');
    Route::post('auth/email-verification/verify', [EmailVerificationController::class, 'verify'])->middleware('throttle:5,1');
    Route::get('dashboard', [DashboardController::class, 'show'])->middleware('throttle:60,1');
    Route::get('/affirmations/today', [AffirmationController::class, 'today'])->middleware('throttle:60,1');
    Route::post('/affirmations/read', [AffirmationController::class, 'read'])->middleware('throttle:60,1');
    Route::prefix('/insights')->group(function () {
        Route::get('/weekly', [InsightsController::class, 'weekly'])->middleware('throttle:60,1');
        Route::get('/triggers', [InsightsController::class, 'triggers'])->middleware('throttle:60,1');
        Route::get('/sleep-mood', [InsightsController::class, 'sleepMood'])->middleware('throttle:60,1');
    });
    Route::post('auth/logout', [AuthController::class, 'logout']);
    $sessions = AuthSessionController::class;
    Route::post('auth/revoke-refresh', [$sessions, 'revokeRefresh'])->middleware('throttle:30,1');
    Route::post('auth/logout-all', [$sessions, 'destroyAll'])->middleware('throttle:30,1');
    Route::get('me/sessions', [$sessions, 'index'])->middleware('throttle:30,1');
    Route::get('user/sessions', [$sessions, 'index'])->middleware('throttle:30,1');
    Route::delete('user/sessions/{id}', [$sessions, 'destroy'])->middleware('throttle:30,1');
    Route::delete('me/sessions/{id}', [$sessions, 'destroy'])->middleware('throttle:30,1');
    Route::delete('me/sessions', [$sessions, 'destroyAll'])->middleware('throttle:30,1');
    Route::post('me/password', [$sessions, 'password'])->middleware('throttle:5,1');
    Route::post('me/device-security', DeviceSecurityController::class)->middleware('throttle:10,60');
    Route::delete('account', [AccountController::class, 'destroy'])->middleware('throttle:5,1');
    Route::get('me', [ProfileController::class, 'show']);
    Route::get('student-verification', [\App\Http\Controllers\Api\V1\StudentVerificationController::class, 'show']);
    Route::post('student-verification', [\App\Http\Controllers\Api\V1\StudentVerificationController::class, 'store'])->middleware('throttle:2,1440');
    Route::post('student-verification/{id}/verify-email', [\App\Http\Controllers\Api\V1\StudentVerificationController::class, 'sendVerification'])->whereUuid('id')->middleware('throttle:2,60');
    Route::get('store/config', [\App\Http\Controllers\Api\V1\StoreBillingController::class, 'configuration']);
    Route::post('subscription/verify-receipt', [\App\Http\Controllers\Api\V1\StoreBillingController::class, 'verify'])->middleware('throttle:10,1');
    Route::get('emergency-email', [\App\Http\Controllers\Api\V1\EmergencyEmailController::class, 'show']);
    Route::post('emergency-email', [\App\Http\Controllers\Api\V1\EmergencyEmailController::class, 'store'])->middleware('throttle:2,60');
    Route::delete('emergency-email', [\App\Http\Controllers\Api\V1\EmergencyEmailController::class, 'destroy']);
    Route::post('emergency-email/alerts', [\App\Http\Controllers\Api\V1\EmergencyEmailController::class, 'alert'])->middleware('throttle:10,1');
    Route::get('me/consents', [DocumentConsentController::class, 'index'])->middleware('throttle:60,1');
    Route::post('me/consents', [DocumentConsentController::class, 'store'])->middleware('throttle:30,1');
    Route::get('user/consents', [DocumentConsentController::class, 'index'])->middleware('throttle:60,1');
    Route::post('user/consents', [DocumentConsentController::class, 'store'])->middleware('throttle:30,1');

    Route::get('provider-conversations', [\App\Http\Controllers\Api\V1\Provider\ProviderMessageController::class, 'index']);
    Route::get('provider-conversations/{linkId}/journal-shares/preview', [\App\Http\Controllers\Api\V1\Provider\JournalSharingController::class, 'preview'])->middleware('throttle:30,1');
    Route::post('provider-conversations/{linkId}/journal-shares', [\App\Http\Controllers\Api\V1\Provider\JournalSharingController::class, 'store'])->middleware('throttle:30,1');
    Route::get('provider-conversations/{linkId}/journal-shares/{shareId}', [\App\Http\Controllers\Api\V1\Provider\JournalSharingController::class, 'show'])->middleware('throttle:60,1');
    Route::delete('provider-conversations/{linkId}/journal-shares/{shareId}', [\App\Http\Controllers\Api\V1\Provider\JournalSharingController::class, 'destroy'])->middleware('throttle:30,1');
    Route::get('provider-conversations/{linkId}/messages', [\App\Http\Controllers\Api\V1\Provider\ProviderMessageController::class, 'messages'])->middleware('throttle:60,1');
    Route::post('provider-conversations/{linkId}/messages', [\App\Http\Controllers\Api\V1\Provider\ProviderMessageController::class, 'store'])->middleware('throttle:60,1');
    Route::post('provider-conversations/{linkId}/read', [\App\Http\Controllers\Api\V1\Provider\ProviderMessageController::class, 'read'])->middleware('throttle:60,1');
    Route::get('provider-conversations/{linkId}/records', [\App\Http\Controllers\Api\V1\Provider\ProviderMessageController::class, 'records'])->middleware('throttle:60,1');
    Route::get('me/provider/links', [PatientConsentController::class, 'index']);
    Route::get('me/provider-access-log', [ProviderAccessLogController::class, 'patient']);
    Route::get('me/resources', [ProviderAssignmentController::class, 'patientIndex']);
    Route::patch('me/resources/{assignmentId}', [ProviderAssignmentController::class, 'patientUpdate']);
    Route::patch('me/provider/{linkId}/consent', [PatientConsentController::class, 'update']);
    Route::delete('me/provider/{linkId}', [PatientConsentController::class, 'destroy']);
    Route::patch('me', [ProfileController::class, 'update']);
    Route::get('mood-entries/stats', MoodStatsController::class)->middleware('throttle:60,1');
    Route::get('mood-entries/stats', MoodStatsController::class)->middleware('throttle:60,1');
    Route::apiResource('mood-entries', MoodEntryController::class);
    Route::apiResource('journal-entries', JournalEntryController::class);
    Route::get('journals', [JournalEntryController::class, 'index']);
    Route::apiResource('conversations', ChatController::class);
    Route::get('conversations/{id}/messages', [ChatController::class, 'messages'])->middleware('throttle:60,1');
    Route::get('user/profile/avatar', [\App\Http\Controllers\Api\V1\ProfileAvatarController::class, 'show']);
    Route::post('user/profile/avatar', [\App\Http\Controllers\Api\V1\ProfileAvatarController::class, 'upload'])->middleware('throttle:10,1');
    Route::put('user/profile/avatar', [\App\Http\Controllers\Api\V1\ProfileAvatarController::class, 'preset']);
    Route::delete('user/profile/avatar', [\App\Http\Controllers\Api\V1\ProfileAvatarController::class, 'destroy']);
    Route::get('next-of-kin', [\App\Http\Controllers\Api\V1\NextOfKinController::class, 'show']);
    Route::put('next-of-kin', [\App\Http\Controllers\Api\V1\NextOfKinController::class, 'store']);
    Route::delete('next-of-kin', [\App\Http\Controllers\Api\V1\NextOfKinController::class, 'destroy']);
    Route::get('safety-plan', [SafetyPlanController::class, 'show']);
    Route::put('safety-plan', [SafetyPlanController::class, 'store']);
    Route::get('user/profile', [UserProfileController::class, 'show']);
    Route::patch('user/profile', [UserProfileController::class, 'update']);
    Route::patch('user/preferences', [UserProfileController::class, 'updatePreferences']);
    Route::patch('user/notification-preferences', [UserProfileController::class, 'updateNotificationPreferences']);
    Route::delete('provider/invitations/{linkId}', [ProviderController::class, 'cancelInvitation'])->middleware('throttle:30,1');
    Route::get('provider', [ProviderController::class, 'show']);
    Route::post('provider/request', [ProviderController::class, 'requestCode'])->middleware('throttle:5,1');
    Route::post('provider/verify', [ProviderController::class, 'verify'])->middleware('throttle:10,1');
    Route::delete('provider', [ProviderController::class, 'destroy']);
    Route::apiResource('quiz-attempts', QuizController::class);
    Route::prefix('quizzes')->middleware('throttle:60,1')->group(function () {
        $quizzes = QuizCatalogController::class;
        Route::get('/', [$quizzes, 'index']);
        Route::get('attempts', [$quizzes, 'attempts']);
        Route::get('attempts/{id}', [$quizzes, 'attempt']);
        $sharing = \App\Http\Controllers\Api\V1\QuizSharingController::class;
        Route::post('attempts/{id}/share', [$sharing, 'provider'])->middleware('throttle:10,1');
        Route::get('versions/{id}', [$quizzes, 'version']);
        Route::post('submit-offline', [$quizzes, 'offline'])->middleware('throttle:10,1');
        Route::get('recipients/resolve', [$sharing, 'resolve'])->middleware('throttle:20,1');
        Route::get('shared', [$sharing, 'received']);
        Route::get('shared/{id}', [$sharing, 'receivedDetail']);
        Route::delete('shared/{id}', [$sharing, 'dismiss']);
        Route::post('invitations/claim', [$sharing, 'claim'])->middleware('throttle:10,1');
        Route::post('attempts/{id}/friends', [$sharing, 'friend'])->middleware('throttle:10,1');
        Route::post('attempts/{id}/invitations', [$sharing, 'invitation'])->middleware('throttle:10,1');
        Route::get('attempts/{id}/grants', [$sharing, 'grants']);
        Route::delete('attempts/{id}/grants/{kind}/{shareId}', [$sharing, 'revoke']);
        Route::get('attempts/{id}/pdf', [$sharing, 'pdf'])->middleware('throttle:10,1');
        Route::get('{slug}', [$quizzes, 'show']);
        Route::post('{id}/start', [$quizzes, 'start'])->middleware('throttle:10,1');
        Route::post('{id}/submit', [$quizzes, 'submit'])->middleware('throttle:10,1');
    });
    Route::get('quiz-results', [QuizController::class, 'index']);
    Route::get('content/saved', [ContentController::class, 'index'])->middleware('throttle:60,1');
    Route::prefix('content')->middleware('throttle:60,1')->group(function () {
        $articles = ArticleController::class;
        Route::get('articles', [$articles, 'index']);
        Route::get('categories', [$articles, 'categories']);
        Route::get('search', [$articles, 'search']);
        Route::get('bookmarks', [ContentController::class, 'index']);
        Route::get('articles/{slug}', [$articles, 'show']);
        Route::post('articles/{id}/bookmark', [$articles, 'bookmark']);
        Route::delete('articles/{id}/bookmark', [$articles, 'unbookmark']);
        Route::post('articles/{id}/save', [$articles, 'bookmark']);
        Route::delete('articles/{id}/save', [$articles, 'unbookmark']);
    });
    Route::post('notifications/register-token', [PushDeviceController::class, 'store'])->middleware('throttle:30,1');
    Route::delete('notifications/devices/{deviceId}', [PushDeviceController::class, 'destroy'])->middleware('throttle:30,1');
    Route::get('notifications/preferences', [UserProfileController::class, 'notificationPreferences'])->middleware('throttle:60,1');
    Route::patch('notifications/preferences', [UserProfileController::class, 'updateNotificationPreferences'])->middleware('throttle:30,1');
    Route::get('crisis/resources', CrisisResourceController::class)->middleware('throttle:60,1');
    Route::prefix('community')->middleware([CommunityReady::class, 'throttle:60,1'])->group(function () {
        $community = CommunityController::class;
        Route::get('posts', [$community, 'index']);
        Route::put('{kind}/{id}/reaction', [$community, 'reaction'])->where('kind', 'posts|comments')->whereUuid('id');
        Route::get('users/{id}/posts', [$community, 'userPosts'])->whereNumber('id');
        Route::post('posts', [$community, 'store'])->middleware('throttle:10,1');
        Route::get('posts/{id}', [$community, 'show']);
        Route::patch('posts/{id}', [$community, 'update'])->middleware('throttle:10,1');
        Route::delete('posts/{id}', [$community, 'destroy']);
        Route::get('posts/{id}/comments', [$community, 'comments']);
        Route::post('posts/{id}/comments', [$community, 'comment'])->middleware('throttle:20,1');
        Route::delete('comments/{id}', [$community, 'deleteComment']);
        Route::post('posts/{id}/report', [$community, 'reportPost'])->middleware('throttle:10,1');
        Route::post('comments/{id}/report', [$community, 'reportComment'])->middleware('throttle:10,1');
        Route::post('users/{id}/block', [$community, 'block']);
        Route::delete('users/{id}/block', [$community, 'unblock']);
        Route::get('blocked', [$community, 'blocked']);
        Route::get('groups', [$community, 'groups']);
        Route::post('groups/{id}/join', [$community, 'join']);
        Route::delete('groups/{id}/leave', [$community, 'leave']);
        Route::get('groups/{id}/posts', [$community, 'groupPosts']);
        Route::post('posts/{id}/image', [$community, 'image'])->middleware('throttle:10,1');
    });
    Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount'])->middleware('throttle:60,1');
    Route::patch('notifications/read-all', [NotificationController::class, 'markAllAsRead'])->middleware('throttle:60,1');
    Route::patch('notifications/{id}/read', [NotificationController::class, 'markAsRead'])->middleware('throttle:60,1');
    Route::apiResource('notifications', NotificationController::class);
    Route::get('subscription', [SubscriptionController::class, 'show']);
    Route::post('support/reports', [\App\Http\Controllers\Api\V1\SupportController::class, 'store'])->middleware('throttle:5,1');
    Route::get('subscription/prices', [SubscriptionController::class, 'prices'])->middleware('throttle:60,1');
    Route::post('sync/batch', [SyncController::class, 'batch'])->middleware('throttle:60,1');
});

// Provider accounts use their own model-scoped Sanctum guard. Patient linking routes above are unchanged.
Route::prefix('v1/guardian')->middleware('throttle:10,1')->group(function () {
    $retiredAge = fn () => response()->json(['message' => 'The custom age and guardian process has been retired.', 'code' => 'age_flow_retired'], 410);
    Route::post('confirm', $retiredAge);
    Route::post('review', $retiredAge);
    Route::post('decision', $retiredAge);
    Route::post('withdrawal', $retiredAge);
});
Route::get('v1/crisis/public-resources', CrisisResourceController::class)->middleware('throttle:60,1');
Route::prefix('v1/admin/age-cases')->middleware(['auth:sanctum', 'role:admin', 'admin.manage:guardians', 'throttle:30,1'])->group(function () {
    Route::get('/', fn () => response()->json(['code' => 'age_flow_retired'], 410));
    Route::post('{id}/resolve', fn () => response()->json(['code' => 'age_flow_retired'], 410))->whereUuid('id');
});
Route::prefix('v1/provider')->group(function () {
    Route::post('auth/refresh', [AuthSessionController::class, 'refresh'])->middleware('throttle:30,1');
    $controller = ProviderAuthController::class;
    Route::post('auth/register', [$controller, 'register'])->middleware('throttle:5,1');
    Route::post('auth/login', [$controller, 'login'])->middleware(['throttle:10,1', LoginLockout::class]);
    Route::post('auth/forgot-password', [$controller, 'forgotPassword'])->middleware(['throttle:5,1', 'throttle:provider-password-reset-email']);
    Route::post('auth/reset-password', [$controller, 'resetPassword'])->middleware('throttle:5,1');
    Route::middleware(['auth:provider', EnsureActiveProvider::class])->group(function () use ($controller) {
    Route::post('audio-attachments', [\App\Http\Controllers\Api\V1\AudioAttachmentController::class, 'store'])->middleware('throttle:20,1');
    Route::get('audio-attachments/{id}', [\App\Http\Controllers\Api\V1\AudioAttachmentController::class, 'show'])->whereUuid('id');

        Route::post('auth/logout', [$controller, 'logout']);
        Route::post('invitations/preview', [\App\Http\Controllers\Api\V1\Provider\ProviderInvitationController::class, 'preview'])->middleware('throttle:provider-invitation-code');
        Route::post('invitations/connect', [\App\Http\Controllers\Api\V1\Provider\ProviderInvitationController::class, 'connect'])->middleware('throttle:provider-invitation-code');
        Route::post('auth/revoke-refresh', [AuthSessionController::class, 'revokeRefresh'])->middleware('throttle:30,1');
        Route::get('me', [$controller, 'me']);
        Route::get('consent-config', [DocumentConsentController::class, 'configuration'])->middleware('throttle:60,1');
        Route::get('me/consents', [DocumentConsentController::class, 'index'])->middleware('throttle:60,1');
        Route::post('me/consents', [DocumentConsentController::class, 'store'])->middleware('throttle:30,1');
        Route::patch('me', [$controller, 'update']);

    Route::get('conversations', [\App\Http\Controllers\Api\V1\Provider\ProviderMessageController::class, 'index']);
    Route::get('conversations/{linkId}/journal-shares/{shareId}', [\App\Http\Controllers\Api\V1\Provider\JournalSharingController::class, 'show'])->middleware('throttle:60,1');
    Route::get('conversations/{linkId}/messages', [\App\Http\Controllers\Api\V1\Provider\ProviderMessageController::class, 'messages'])->middleware('throttle:60,1');
    Route::post('conversations/{linkId}/messages', [\App\Http\Controllers\Api\V1\Provider\ProviderMessageController::class, 'store'])->middleware('throttle:60,1');
    Route::post('conversations/{linkId}/read', [\App\Http\Controllers\Api\V1\Provider\ProviderMessageController::class, 'read'])->middleware('throttle:60,1');
        $clients = ProviderClientController::class;
        Route::get('quiz-shares', [\App\Http\Controllers\Api\V1\Provider\ProviderQuizSharingController::class, 'index']);
        Route::get('quiz-shares/{id}', [\App\Http\Controllers\Api\V1\Provider\ProviderQuizSharingController::class, 'show']);
        Route::get('clients', [$clients, 'index']);
        Route::get('clients/{userId}', [$clients, 'show']);
        Route::get('clients/{userId}/moods', [$clients, 'moods']);
        Route::get('clients/{userId}/journals', [$clients, 'journals']);
        Route::get('clients/{userId}/quizzes', [$clients, 'quizzes']);
        Route::get('clients/{userId}/consents', [$clients, 'consents']);
        $notes = ProviderNotesController::class;
        Route::get('clients/{userId}/notes', [$notes, 'index']);
        Route::post('clients/{userId}/notes', [$notes, 'store'])->middleware('throttle:30,1');
        Route::delete('clients/{userId}/notes/{noteId}', [$notes, 'destroy']);
        Route::get('access-log', [ProviderAccessLogController::class, 'provider']);
        $resources = ProviderResourceController::class;
        Route::get('resources', [$resources, 'index']);
        Route::post('resources', [$resources, 'store']);
        Route::patch('resources/{resourceId}', [$resources, 'update']);
        Route::delete('resources/{resourceId}', [$resources, 'destroy']);
        $assignments = ProviderAssignmentController::class;
        Route::get('clients/{userId}/resources', [$assignments, 'index']);
        Route::post('clients/{userId}/resources', [$assignments, 'store'])->middleware('throttle:30,1');
        Route::delete('clients/{userId}/resources/{assignmentId}', [$assignments, 'destroy']);
        $reports = ProviderReportController::class;
        Route::get('reports', [$reports, 'index']);
        Route::post('reports', [$reports, 'store']);
        Route::get('reports/{reportId}', [$reports, 'show']);
        Route::get('reports/{reportId}/download', [$reports, 'download']);
        Route::get('reports/{reportId}/file', [$reports, 'file'])->middleware('signed:relative')->name('provider.reports.file');
    });
});

Route::get('v1/emergency-email/{action}', [\App\Http\Controllers\Api\V1\EmergencyEmailController::class, 'tokenPage'])->where('action', 'confirm|unsubscribe')->middleware('throttle:30,1');
Route::post('v1/emergency-email/{action}', [\App\Http\Controllers\Api\V1\EmergencyEmailController::class, 'tokenAction'])->where('action', 'confirm|unsubscribe')->middleware('throttle:10,1');
Route::get('v1/student-email/confirm', [\App\Http\Controllers\Api\V1\StudentVerificationController::class, 'confirm'])->middleware('throttle:10,1');
Route::post('v1/store-events/{platform}', \App\Http\Controllers\StoreWebhookController::class)->where('platform', 'apple|google')->middleware('throttle:120,1');
