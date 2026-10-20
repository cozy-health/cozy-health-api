# Phase 2.3 — Non-cached endpoint audit

Audited 2026-10-07. No endpoint was trimmed: the three cached endpoints are
excluded by the revised task, and the non-cached provider endpoint has an
existing response-contract mismatch. No controller, model, route, migration,
mobile source file, or response changed.

Paths below are relative to the mobile repository unless stated otherwise.

| Endpoint (under /api/v1) | Mobile caller | Cache behavior | Verdict |
|---|---|---|---|
| GET /user/profile (ApiConstants.me; there is no /me route) | lib/features/settings/data/profile_repository.dart:21; saves at line 27 | Replace: LocalDbService.saveUserProfile at lib/core/services/local_db_service.dart:221 writes by profile ID | Preserve complete record |
| GET /notifications | lib/features/notifications/data/notification_repository.dart:18; saves at line 26 | Replace: LocalDbService.saveAppNotification at lib/core/services/local_db_service.dart:331 | Preserve complete record |
| GET /subscription | lib/features/settings/data/subscription_repository.dart:19; saves at line 25 | Replace: LocalDbService.saveSubscriptionStatus at lib/core/services/local_db_service.dart:353 | Preserve complete record |
| GET /provider | lib/features/settings/data/settings_service.dart:31; consumed by lib/features/settings/presentation/screens/provider_screen.dart:51 | No persistent cache; the screen holds the result in its State | Defer until response contract is reconciled |

The non-caching SettingsService profile/subscription callers do not make those
endpoints eligible: ProfileRepository, SubscriptionRepository, and the startup
UserDataFetcher also persist their responses.

## Fields consumed

- Profile parses id, name, username, email, avatar_url, bio, theme, accent_color,
  language, reduce_motion, high_contrast, haptics_enabled, text_size, show_stats,
  show_username, notifications_master, daily_checkin_enabled, daily_checkin_time,
  journal_reminder_enabled, comments_notifications, achievements_notifications,
  marketing_notifications, and client_updated_at (with DateTime.now fallback).
- Notifications parse id, type, title, body, deep_link, data, is_read, read_at,
  and client_created_at.
- Subscription parses id, status, plan, and expires_at. It is cached despite
  having a small parsed field set; no projection change is authorized under
  this task's non-cached-only rule.
- Provider screen reads top-level provider and has_provider. Inside provider
  it reads status, provider_email, provider_name, and provider_phone. Only
  provider_email is currently a connection column. ProviderController::show
  returns providerConnection with a nested provider relation containing name
  and phone; it does not flatten those into provider_name/provider_phone or
  emit has_provider/status. There is no mapper in SettingsService to do this.
  Resolving the contract would require changes beyond SELECT column lists.

## Mobile prerequisite

Repositories parse responses into typed models before calling the save methods.
Missing keys have already become null/default values at that point. A storage
merge of typed model fields alone cannot distinguish an absent field from an
explicit null. Phase 2.5 must preserve JSON key presence at ingestion, retain
cached values for absent keys, and allow explicit nulls to clear nullable fields.
Full-record behavior and local writes must remain intact.

For records with no cached detail, preserving old fields cannot supply missing
body/note content. Summary/detail fetching and incomplete-record handling must
also be ready before list projections are reduced. Rollout order is mobile
implementation, tests, release, on-device verification, then server projections
with a compatibility strategy for older installed clients.

## Outcome

- Endpoints trimmed: none. Removed fields and response-size reductions: none.
- Deferred: mood, journal, chat, quiz, content lists; cached profile,
  notifications and subscription; provider until its contract is reconciled.
- Tracking: DEFERRED.md and shared docs/BUILD_PLAN.md Phase 2.5.
- Validation: php artisan test — 23 passed, 93 assertions.
- No push; no staging or production database operations.
