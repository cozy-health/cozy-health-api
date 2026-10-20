# Cozy Health API Routes

**Last generated:** 2026-10-05  
**Backend inspected:** `cozy-health-api-main`  
**Mobile inspected:** `cozy-health-mobile-app-main-main`  
**Production base URL:** `https://cozy-health-api-production.up.railway.app/api/v1`  
**Regeneration command:** `php artisan route:list --json`

This file records the current API contract during the Stabilization Gate. It is intentionally not just a mirror of `route:list`: it also records mobile calls that currently have no registered backend route, stale test paths, and routes whose controllers return placeholder responses.

## Rules

- Every mobile network call should map to a route in this file.
- Every backend route change should update this file in the same change.
- Drift is a bug. If a test or mobile repository calls a different path from the registered backend route, fix the caller or deliberately add a compatibility route.
- Rate limits must be documented here when route throttling is added.
- A registered route is not automatically production-ready. Placeholder controller responses are marked as stubs.

## Legend

| Symbol | Meaning |
|---|---|
| Auth | Requires Sanctum bearer token |
| Public | Does not require Sanctum bearer token |
| OK | Registered route with non-placeholder behavior observed |
| Stub | Registered route exists, but controller returns an empty or placeholder response |
| Missing | Mobile calls it, but no backend route is registered |
| Drift | Test or mobile path differs from registered backend route |
| Tested | A backend test currently exercises the route |

## Backend Route Summary

`php artisan route:list --json` currently reports:

| Group | Count |
|---|---:|
| Public app API routes under `/api/v1` | 4 |
| Authenticated app API routes under `/api/v1` | 34 |
| App API routes total | 38 |
| Swagger/docs routes | 4 |
| Framework/system routes | 4 |

The app API routes are registered in [routes/api.php](../cozy-health-api-main/routes/api.php). Some mobile data services still define calls that are not wired into `routes/api.php`; those are listed under mobile/backend drift where relevant.

## Auth

| Method | Path | Auth | Backend action | Mobile caller | Test coverage | Status | Notes |
|---|---|---|---|---|---|---|---|
| POST | `/auth/register` | Public | `AuthController@register` | `AuthService.signUp` via `ApiConstants.register` | Tested | OK | Throttled at `5,1`. |
| POST | `/auth/login` | Public | `AuthController@login` | `AuthService.login` via `ApiConstants.login` | Tested | OK | Throttled at `10,1`; 429 preserves `Retry-After`. |
| POST | `/auth/forgot-password` | Public | `PasswordResetController@forgotPassword` | `AuthService.forgotPassword` | Tested | OK | Throttled at `3,1`. |
| POST | `/auth/reset-password` | Public | `PasswordResetController@resetPassword` | `AuthService.resetPassword` | Tested | OK | Throttled at `5,1`. |
| POST | `/auth/logout` | Auth | `AuthController@logout` | `AuthService.logout` | Not found | OK | Deletes current access token. |

## User Profile And Preferences

| Method | Path | Auth | Backend action | Mobile caller | Test coverage | Status | Notes |
|---|---|---|---|---|---|---|---|
| GET | `/user/profile` | Auth | `UserProfileController@show` | `ApiConstants.me`, `SplashService`, `AuthService.currentUser`, `SettingsService.getProfile`, `ProfileRepository.getProfile`, `UserDataFetcher` | Not found | OK | This is the current backend equivalent of `/me`. |
| PATCH | `/user/profile` | Auth | `UserProfileController@update` | `ProfileRepository.updateProfile` | Not found | OK | Mobile `SettingsService.updateProfile` uses `PUT /user/profile`, which is not registered. |
| PATCH | `/user/preferences` | Auth | `UserProfileController@updatePreferences` | `ProfileRepository.updatePreferences` | Not found | OK | Mobile interceptor recognizes this path. |
| PATCH | `/user/notification-preferences` | Auth | `UserProfileController@updateNotificationPreferences` | No direct caller found | Not found | OK | Current backend route exists, but mobile notification services call separate notification preference paths that are missing. |

## Mood Entries

| Method | Path | Auth | Backend action | Mobile caller | Test coverage | Status | Notes |
|---|---|---|---|---|---|---|---|
| GET | `/mood-entries` | Auth | `MoodEntryController@index` | `MoodRepository.fetchMoodEntries`, `UserDataFetcher` | Not found | OK | Returns paginated `MoodEntry` records ordered by `client_created_at`. |
| POST | `/mood-entries` | Auth | `MoodEntryController@store` | Sync/offline paths through `MoodRepository` and `LocalDbService` | Not found | OK | Uses `updateOrCreate(['id' => $data['id']], $data)`. |
| GET | `/mood-entries/{mood_entry}` | Auth | `MoodEntryController@show` | No direct caller found | Not found | OK | Does not verify ownership in the current controller. |
| PUT/PATCH | `/mood-entries/{mood_entry}` | Auth | `MoodEntryController@update` | No direct caller found | Not found | Stub | Returns an empty success response. |
| DELETE | `/mood-entries/{mood_entry}` | Auth | `MoodEntryController@destroy` | No direct caller found | Not found | Stub | Returns an empty success response. |

## Journal Entries

| Method | Path | Auth | Backend action | Mobile caller | Test coverage | Status | Notes |
|---|---|---|---|---|---|---|---|
| GET | `/journal-entries` | Auth | `JournalEntryController@index` | `JournalRepository`, `UserDataFetcher` | Not found | OK | Returns paginated `JournalEntry` records ordered by `client_created_at`. |
| POST | `/journal-entries` | Auth | `JournalEntryController@store` | Local/offline repository paths | Not found | Stub | Returns an empty success response. |
| GET | `/journal-entries/{journal_entry}` | Auth | `JournalEntryController@show` | No direct caller found | Not found | OK | Does not verify ownership in the current controller. |
| PUT/PATCH | `/journal-entries/{journal_entry}` | Auth | `JournalEntryController@update` | No direct caller found | Not found | Stub | Returns an empty success response. |
| DELETE | `/journal-entries/{journal_entry}` | Auth | `JournalEntryController@destroy` | No direct caller found | Not found | Stub | Returns an empty success response. |

## Conversations

| Method | Path | Auth | Backend action | Mobile caller | Test coverage | Status | Notes |
|---|---|---|---|---|---|---|---|
| GET | `/conversations` | Auth | `ChatController@index` | `ChatRepository.fetchConversations`, `UserDataFetcher` | Not found | OK | Returns paginated `ChatConversation` records. |
| POST | `/conversations` | Auth | `ChatController@store` | No direct caller found | Not found | Stub | Returns an empty success response. |
| GET | `/conversations/{conversation}` | Auth | `ChatController@show` | No direct caller found | Not found | OK | Mobile instead calls `/conversations/{id}/messages`, which is missing. |
| PUT/PATCH | `/conversations/{conversation}` | Auth | `ChatController@update` | No direct caller found | Not found | Stub | Returns an empty success response. |
| DELETE | `/conversations/{conversation}` | Auth | `ChatController@destroy` | `ChatRepository.deleteConversation` sync queue, not direct API call | Not found | Stub | Returns an empty success response. |

## Safety Plan

| Method | Path | Auth | Backend action | Mobile caller | Test coverage | Status | Notes |
|---|---|---|---|---|---|---|---|
| GET | `/safety-plan` | Auth | `SafetyPlanController@show` | `SafetyPlanRepository.fetch`, `UserDataFetcher` | Tested | OK | Returns the authenticated user safety plan or 404 when none exists. |
| PUT | `/safety-plan` | Auth | `SafetyPlanController@store` | No direct caller found | Not found | Stub | Returns an empty success response. |

## Quiz Attempts

| Method | Path | Auth | Backend action | Mobile caller | Test coverage | Status | Notes |
|---|---|---|---|---|---|---|---|
| GET | `/quiz-attempts` | Auth | `QuizController@index` | `QuizRepository`, `UserDataFetcher` | Not found | OK | Returns paginated `QuizAttempt` records. |
| POST | `/quiz-attempts` | Auth | `QuizController@store` | No direct caller found | Not found | Stub | Returns an empty success response. |
| GET | `/quiz-attempts/{quiz_attempt}` | Auth | `QuizController@show` | No direct caller found | Not found | OK | Does not verify ownership in the current controller. |
| PUT/PATCH | `/quiz-attempts/{quiz_attempt}` | Auth | `QuizController@update` | No direct caller found | Not found | Stub | Returns an empty success response. |
| DELETE | `/quiz-attempts/{quiz_attempt}` | Auth | `QuizController@destroy` | No direct caller found | Not found | Stub | Returns an empty success response. |

## Notifications

| Method | Path | Auth | Backend action | Mobile caller | Test coverage | Status | Notes |
|---|---|---|---|---|---|---|---|
| GET | `/notifications` | Auth | `NotificationController@index` | `NotificationService.fetch`, `NotificationRepository.fetchNotifications`, `UserDataFetcher` | Not found | OK | Returns paginated `AppNotification` records. |
| POST | `/notifications` | Auth | `NotificationController@store` | No direct caller found | Not found | Stub | Returns an empty success response. |
| GET | `/notifications/{notification}` | Auth | `NotificationController@show` | No direct caller found | Not found | OK | Does not verify ownership in the current controller. |
| PUT/PATCH | `/notifications/{notification}` | Auth | `NotificationController@update` | No direct caller found | Not found | Stub | Mobile calls `/notifications/{id}/read`, not this route. |
| DELETE | `/notifications/{notification}` | Auth | `NotificationController@destroy` | `NotificationService.delete` | Not found | Stub | Returns an empty success response. |

## Subscription

| Method | Path | Auth | Backend action | Mobile caller | Test coverage | Status | Notes |
|---|---|---|---|---|---|---|---|
| GET | `/subscription` | Auth | `SubscriptionController@show` | `SettingsService.getSubscription`, `SubscriptionRepository.fetch`, `UserDataFetcher` | Tested | OK | Returns authenticated user subscription, or `{plan: null, status: "none"}` when none exists. |

## Sync

| Method | Path | Auth | Backend action | Mobile caller | Test coverage | Status | Notes |
|---|---|---|---|---|---|---|---|
| POST | `/sync/batch` | Auth | `SyncController@batch` | `LocalDbService.processSyncQueue` | Not found | Drift | Backend expects `operations`; mobile appears to send sync queue payloads through `LocalDbService`. Verify payload shape before relying on this route. |

## Swagger And System Routes

These are registered by framework packages or Laravel internals and are not mobile API contract routes.

| Method | Path | Auth | Action | Status |
|---|---|---|---|---|
| GET/HEAD | `/api/documentation` | Public | Swagger UI | OK |
| GET/HEAD | `/api/oauth2-callback` | Public | Swagger OAuth callback | OK |
| GET/HEAD | `/docs` | Public | Swagger docs | OK |
| GET/HEAD | `/docs/asset/{asset}` | Public | Swagger asset | OK |
| GET/HEAD | `/sanctum/csrf-cookie` | Public | Sanctum CSRF cookie | OK |
| GET/HEAD | `/up` | Public | Laravel health check | OK |
| GET/HEAD | `/storage/{path}` | Public | Local storage read | Framework |
| PUT | `/storage/{path}` | Public | Local storage upload | Framework |

## Mobile Calls Without Registered Backend Routes

These calls exist in the Flutter app but are not registered in current `routes/api.php`.

| Method | Path | Mobile caller | Related backend code | Status | Notes |
|---|---|---|---|---|---|
| GET | `/provider` | `SettingsService.getProvider` | `ProviderController@show` exists but is not routed | Missing | Add route or remove mobile flow until provider connections are ready. |
| POST | `/provider/request` | `SettingsService.requestProvider` | `ProviderController@requestCode` exists but is not routed | Missing | Controller expects provider verification request. |
| POST | `/provider/verify` | `SettingsService.verifyProvider` | `ProviderController@verify` exists but is not routed | Missing | Controller exists but route is absent. |
| DELETE | `/provider` | `SettingsService.removeProvider` | `ProviderController@destroy` exists but is not routed | Missing | Controller exists but route is absent. |
| GET | `/subscription/packages` | `SettingsService.getPackages` | No registered route found | Missing | Payment/subscription package work is post-MVP in build plan. |
| POST | `/subscription/activate` | `SettingsService.activateSubscription` | No registered route found | Missing | Payment/subscription package work is post-MVP in build plan. |
| POST | `/subscription/cancel` | `SettingsService.cancelSubscription` | No registered route found | Missing | Payment/subscription package work is post-MVP in build plan. |
| POST | `/subscription/verify-receipt` | `SubscriptionRepository.verifyReceipt` | No registered route found | Missing | Payment/subscription package work is post-MVP in build plan. |
| POST | `/subscription/restore` | `SubscriptionRepository.restore` | No registered route found | Missing | Payment/subscription package work is post-MVP in build plan. |
| GET | `/mood-checkins` | Removed with orphaned old mood flow | Old controller deleted | Resolved | Mobile flow, constants, stale test, controller, models, and lookup tables were removed in Path A cleanup. |
| POST | `/mood-checkins` | Removed with orphaned old mood flow | Old controller deleted | Resolved | New mood flow uses `/mood-entries`. |
| GET | `/mood-checkins/today` | Removed with orphaned old mood flow | Old controller deleted | Resolved | New mood flow uses `/mood-entries`. |
| GET | `/feelings` | Removed with orphaned old mood flow | Lookup controller deleted | Resolved | Old lookup seed tables are scheduled to be dropped by migration. |
| GET | `/feeling-expressions` | Removed with orphaned old mood flow | Lookup controller deleted | Resolved | Old lookup seed tables are scheduled to be dropped by migration. |
| GET | `/feeling-causes` | Removed with orphaned old mood flow | Lookup controller deleted | Resolved | Old lookup seed tables are scheduled to be dropped by migration. |
| GET | `/coping-mechanisms` | Removed with orphaned old mood flow | Lookup controller deleted | Resolved | Old lookup seed tables are scheduled to be dropped by migration. |
| GET | `/mood-entries/stats` | `MoodRepository.fetchStats` | No registered route found | Missing | Phase 2 wants server-side aggregate stats. |
| GET | `/journals` | `JournalService.getJournals` | `JournalController@index` exists but is not routed | Missing | Backend currently exposes `/journal-entries`. |
| POST | `/journals` | `JournalService.createJournal` | `JournalController@store` exists but is not routed | Missing | Backend currently exposes `/journal-entries`. |
| PUT | `/journals/{id}` | `JournalService.updateJournal` | `JournalController@update` exists but is not routed | Missing | Backend currently exposes `/journal-entries/{id}`. |
| DELETE | `/journals/{id}` | `JournalService.deleteJournal` | `JournalController@destroy` exists but is not routed | Missing | Backend currently exposes `/journal-entries/{id}`. |
| GET | `/conversations/{id}/messages` | `ChatRepository.fetchMessages` | No registered route found | Missing | Backend resource show is `/conversations/{id}` only. |
| POST | `/conversations/{id}/messages` | `ChatRepository.sendMessage` | No registered route found | Missing | Mobile interceptor has special handling for this path. |
| GET | `/quizzes` | `QuizService.getQuizzes` | No registered route found | Missing | Backend currently exposes `/quiz-attempts`, not quiz catalog routes. |
| GET | `/quizzes/{id}` | `QuizService.getQuiz` | No registered route found | Missing | Backend currently exposes `/quiz-attempts/{id}`. |
| POST | `/quizzes/{id}/submit` | `QuizService.submitQuiz` | No registered route found | Missing | Backend currently exposes `/quiz-attempts`. |
| GET | `/quiz-results` | `QuizService.getMyResults`, `UserDataFetcher` fallback | No registered route found | Missing | Backend currently exposes `/quiz-attempts`. |
| GET | `/content/saved` | `ContentRepository`, `UserDataFetcher` | No registered route found | Missing | Content routes are not registered. |
| POST | `/content/articles/{id}/save` | `ContentRepository.saveArticle` | No registered route found | Missing | Content routes are not registered. |
| DELETE | `/content/articles/{id}/save` | `ContentRepository.unsaveArticle` | No registered route found | Missing | Content routes are not registered. |
| GET | `/activity/overview` | Unused `ActivityService.getOverview` | `ActivityController` deleted | Dead | Service is defined but not invoked by reachable mobile UI. |
| GET | `/activity/mood-chart` | Unused `ActivityService.getMoodChart` | `ActivityController` deleted | Dead | Service is defined but not invoked by reachable mobile UI. |
| GET | `/activity/common-triggers` | Unused `ActivityService.getCommonTriggers` | `ActivityController` deleted | Dead | Service is defined but not invoked by reachable mobile UI. |
| GET | `/activity/journal-stats` | Unused `ActivityService.getJournalStats` | `ActivityController` deleted | Dead | Service is defined but not invoked by reachable mobile UI. |
| GET | `/activity/recommendations` | Unused `ActivityService.getRecommendations` | `ActivityController` deleted | Dead | Service is defined but not invoked by reachable mobile UI. |
| GET | `/notifications/unread-count` | `NotificationService.getUnreadCount` | No registered route found | Missing | Add dedicated route or calculate from `/notifications`. |
| POST | `/notifications/{id}/read` | `NotificationService.markRead` | No registered route found | Missing | `NotificationRepository` also calls `PATCH /notifications/{id}/read`; neither route exists. |
| PATCH | `/notifications/{id}/read` | `NotificationRepository.markRead` | No registered route found | Missing | Current backend only has resource update at `PATCH /notifications/{id}`. |
| POST | `/notifications/read-all` | `NotificationService.markAllRead` | No registered route found | Missing | `NotificationRepository` calls `PATCH /notifications/read-all`; neither route exists. |
| PATCH | `/notifications/read-all` | `NotificationRepository.markAllRead` | No registered route found | Missing | Route absent. |
| PUT | `/user/profile` | `SettingsService.updateProfile` | Only `PATCH /user/profile` is registered | Drift | Change mobile to PATCH or add PUT route deliberately. |

## Backend Tests Calling Stale Routes

| File | Current call | Registered route | Status | Recommended fix |
|---|---|---|---|---|
| `cozy-health-api-main/tests/Feature/AuthTest.php` | `POST /api/v1/auth/register` | `POST /api/v1/auth/register` | Resolved | Fixed in `51243bb`. |
| `cozy-health-api-main/tests/Feature/MoodCheckinTest.php` | Deleted | Old mood check-in system removed | Resolved | Removed with orphaned old mood backend cleanup in `04b5eec`. |

## Current Stabilization Findings

| Finding | Impact | Suggested gate action |
|---|---|---|
| Auth route throttling is now applied inline in `routes/api.php`. | Brute-force and email-abuse risk reduced. | Keep limits documented and extend only deliberately. |
| Backend auth and mood test drift is resolved. | `php artisan test` now passes locally after stale mood test deletion. | Keep route changes paired with test/doc updates. |
| Mobile still has missing or dead service paths outside the old mood flow. | Some paths are planned features; some are dead data services. | Triage remaining missing calls by reachable UI before implementation. |
| Several resource controller methods return empty success responses. | Routes look implemented but do not perform real work. | Mark as stubs and either implement or remove from MVP route contract. |
| `GET /safety-plan` and `GET /subscription` now use the authenticated user. | Runtime 500s fixed. | Keep tests covering no-record and has-record cases. |
| Some show/update/delete methods do not verify ownership. | Potential cross-user data access risk. | Add scoped queries by authenticated user. |

## Counts

| Category | Count |
|---|---:|
| Registered app API routes | 38 |
| Registered app API routes marked OK | 24 |
| Registered app API routes marked Stub | 14 |
| Registered app API routes marked Drift | 0 |
| Mobile-called/dead service paths without registered backend routes | 35 |
| Backend test drift issues | 0 |

## Change Log

| Date | Change |
|---|---|
| 2026-10-05 | Initial Stabilization Gate route documentation generated from backend routes, mobile calls, and feature tests. |
| 2026-10-05 | Updated after auth throttling, safety/subscription signature fixes, AuthTest fix, and old mood system removal. |

## Regeneration Checklist

1. Run `php artisan route:list --json` in `cozy-health-api-main`.
2. Review `routes/api.php` and any API routes in other route files.
3. Search Flutter callers in `lib/core/constants/api_constants.dart`, `lib/core/api`, `lib/core/repositories`, `lib/core/services`, and `lib/features/**/data`.
4. Search backend tests for `getJson`, `postJson`, `putJson`, `patchJson`, `deleteJson`, and hard-coded `/api/` paths.
5. Update this file manually after reviewing drift; do not blindly overwrite it from generated output.
