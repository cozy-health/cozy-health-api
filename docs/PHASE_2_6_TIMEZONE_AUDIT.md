# Phase 2.6 timezone audit

## Original date/time matches in app/

| Location | Category | Action |
|---|---|---|
| JournalController.php:3, today() default entry_date | User-facing calendar date | Use the user's localToday date string; controller currently has no registered route |
| UpdateProfileRequest.php:4, before:today | User-facing birth date validation (additional search finding) | Validate before the user's local date |
| NotificationController.php:31 and :42, now() | Absolute read event timestamps | Preserve UTC/app timestamp behavior |
| PasswordResetController.php:37, Carbon::now() | Security token creation timestamp | Preserve absolute time |
| PasswordResetController.php:38, Carbon::now()->addMinutes(15) | Security expiry interval | Preserve absolute time |
| PasswordResetController.php:69, Carbon::parse(...)->isPast() | Security expiry comparison (additional search finding) | Preserve absolute time |
| ProviderController.php:3, now()->addMinutes(15), isPast(), now() | Security expiry and verification timestamps | Preserve absolute time |

No original startOfDay/endOfDay, whereDate, startOfWeek/startOfMonth,
subDays/addDays, date-range queries, scheduled jobs or system log clock calls
were found in app/. routes/console.php has no scheduler declarations.

## Missing implementations

The backend has no streak calculator, Home daily mood query, weekly/monthly
insights grouping or affirmation refresh. No last_affirmation_date column is
used. Do not add these features or columns during this task. Consequently the
requested streak/daily-query endpoint tests cannot honestly be implemented.
Test local clock boundaries and existing profile/journal behavior instead;
endpoint-level regression tests belong with those features when implemented.

The live profile routes use UserProfileController and a separate user_profiles
table. Timezone remains stored only on users and is added to profile payloads
without changing their envelopes. GET/PATCH /api/v1/me now expose the existing
ProfileController, whose user serialization includes timezone. Register/login
serialize User directly; a model default ensures the registration response has
UTC before the database row is refreshed. A timezone accessor normalizes null
to UTC for serialization and helpers. Logout returns null and stays unchanged.

Repeated profile-request tests exposed a cached null profile relationship:
after lazy creation, reusing that user instance could attempt another insert
and violate user_profiles.user_id uniqueness. getOrCreateProfile now queries
the relationship rather than reusing the cached null value.

Only a users.timezone migration is added, with no explicit data backfill or
production migration execution. Existing rows acquire the column's UTC default
when the migration is applied. PHP timezone validation is provided by the
installed Laravel framework (composer requires Laravel 12, not 11).

Future daily/range queries should use UTC instants derived from local start
and next local day start, with a half-open interval to handle DST and fractional
seconds. Future reminders must interpret notification preferences in this
timezone. No cron or scheduler change is part of Phase 2.6.

## Verification

php artisan test: 34 passed, 145 assertions (23 existing + 11 timezone tests).
The suite uses SQLite in memory; no live database was migrated. Tests cover
registration and login payloads, me/profile updates, invalid/null validation,
UTC/null fallback, Auckland midnight boundaries, Tokyo day boundaries, a DST
transition, journal defaults/explicit dates and local birth-date validation.
