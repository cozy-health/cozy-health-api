# Rolling 24-hour affirmations

The existing 30-entry AffirmationSeeder is the catalog. Re-running it neither
duplicates entries nor re-enables disabled entries. Deployment now fails visibly
if seeding fails instead of silently ignoring that failure.

## Assignment contract

Authenticated GET /api/v1/affirmations/today returns data containing:

- id: selected catalog entry ID.
- body: the exact display/copy text.
- assigned_at and expires_at: UTC ISO 8601 timestamps, exactly 24 hours apart.
- date: the user's current local date, retained for older clients only.

With no active entries, data is null. The route, envelope, authentication and
rate limit are unchanged. New clients require the timestamp fields and must be
released after the backend migration/API deployment.

A user's first request chooses a deterministic user-ID offset in the active list.
Until expiry, all devices receive the same persisted assignment. Midnight, DST,
timezone changes, reauthentication and reinstall do not restart its clock. At or
after expiry, the next request advances one active entry in ID order (wrapping)
and starts a fresh 24 hours. A returning user advances only once, regardless of
absence length. A stable multi-entry catalog is exhausted before repeating; a
single entry may repeat. Disabled/deleted current entries are replaced immediately
when requested. Catalog body edits are reflected when the server is queried.

The user_affirmation_assignments table has one primary-keyed row per user.
Requests lock the user row transactionally before reading/creating assignments,
including the initial assignment. Deleting a user cascades the assignment. The
selected ID also serves as the rotation cursor and deliberately survives deletion
of a catalog entry, so rotation can continue to the next active ID.

## Release checks

1. Apply migration 2026_10_16_000002_create_user_affirmation_assignments and deploy
   the API before the mobile update. No historical viewing times are backfilled.
2. Run the repeatable catalog seeder and verify the live active count through the
   normal authorized service console. Do not re-enable deliberately disabled copy.
3. With a test account, confirm repeated requests agree on id/assigned_at/expires_at;
   check that the legacy date and body fields remain available.
4. Install the new mobile build and verify Home/detail agreement, offline status,
   copying, resume, expiry, re-login and a second device. Old builds retain their
   old midnight refresh timing and cannot guarantee exact rolling expiry.

No production migration, seeding or deployment is performed by local tests.

## Verification

Run php -d extension=gd vendor/bin/pest tests/Feature/AffirmationTest.php.
The dedicated affirmations-mysql-concurrency workflow runs eight simultaneous
processes at first assignment and expiry, behind a start barrier and user-row
lock. It verifies one row, one selection and one new window. Its script refuses
ordinary/nonlocal databases and only recreates the dedicated disposable
cozy_affirmation_concurrency_test database as cozy_affirmation_test.

Local SQLite feature tests do not prove MySQL locking. If Docker/MySQL is not
running, the concurrency workflow remains an explicit outstanding check.
