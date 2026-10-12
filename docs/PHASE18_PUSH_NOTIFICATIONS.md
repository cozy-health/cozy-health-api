# Phase 18 — Push reminders

## Implemented behavior

Laravel schedules mood, streak, journal and affirmation candidates in the user's saved IANA timezone. Explicit `push_consent` is required in addition to the master/category switches. Existing profile defaults never authorize delivery.

At most three logical notifications may dispatch per local day; at least two elapsed hours separate sends, including across midnight. All installations share one allowance. Priority is mood, streak, journal, affirmation. The user row is locked while reserving allowance. A reserved batch conservatively consumes its slot even when delivery fails or is uncertain.

Quiet hours default to 22:00–08:00 and are editable or disabled. Equal boundaries mean no quiet interval. Deferred candidates are rechecked before sending. Mood can roll into the next day, merging with that day's reminder; completed check-ins cancel it. Streak, journal and affirmation expire at their original midnight. Unselected weekdays and exhausted allowance do not create a backlog. Schedule changes recalculate pending candidates. New candidates more than 15 minutes past their eligible time are not generated after an outage.

Initial delivery attempts more than 15 minutes late expire. Explicit provider rejection with 429/500/503 can retry with bounded backoff; ambiguous failures and interrupted sends never replay automatically. Android TTL and APNs expiration are zero: offline phones can miss an alert rather than receive it later inside quiet hours. Provider acceptance is not a receipt or display guarantee.

## Interfaces

Existing `GET/PATCH /api/v1/notifications/preferences` now includes `push_consent` and `reminder_settings`. Profile responses and `user_profile` sync support both fields. Settings use ISO weekdays 1–7 and `HH:mm` times:

```json
{
  "push_consent": true,
  "reminder_settings": {
    "mood_days": [1,2,3,4,5,6,7],
    "journal_time": "18:00", "journal_days": [7],
    "streak_enabled": false, "streak_time": "20:00", "streak_days": [1,2,3,4,5,6,7],
    "affirmation_enabled": false, "affirmation_time": "12:00", "affirmation_days": [1,2,3,4,5,6,7],
    "quiet_enabled": true, "quiet_start": "22:00", "quiet_end": "08:00"
  }
}
```

Mood enable/time and journal enable retain the existing `daily_checkin_enabled`, `daily_checkin_time`, and `journal_reminder_enabled` fields. Partial settings updates merge existing values. Delivery ledgers are not exposed through mobile sync.

Registration returns `data.cleanup_token` alongside its existing public device fields. Store this revocation-only credential securely. `POST /api/v1/notifications/cleanup` accepts `device_id` and `cleanup_token`, requires no login, is throttled, and always returns a generic completion response. Rotating registration rotates the cleanup credential. An old credential cannot revoke a later registration or another installation. This enables offline logout cleanup after the original access token expires.

## Configuration and deployment

1. Create development and production Firebase projects; register the current Android and iOS identifiers. Keep environments separate.
2. Enable the FCM HTTP v1 API and create a service account with the minimum messaging permission.
3. Store its JSON as the hosting secret `FIREBASE_CREDENTIALS_JSON`, or mount a readable secret file and set `FIREBASE_CREDENTIALS_PATH`. Set `FIREBASE_PROJECT_ID` to the matching project.
4. Apply migrations through the normal deployment. The Docker supervisor now runs the database queue worker and `schedule:work` alongside the web processes. Keep one scheduler replica; larger deployments require shared locking and a dedicated scheduler service.
5. Keep `PUSH_ENABLED=false` until configuration and internal device tests pass. Keep `PUSH_IOS_ENABLED=false` until signed iPhone acceptance. The `push_notifications` feature flag is checked directly at scheduling and send time.
6. Enable internal Android sends with `PUSH_ENABLED=true`. Enable iOS separately only after its signing and device gate.

`php artisan push:tick` executes one scheduling pass, subject to these switches. `php artisan queue:work database --tries=1 --timeout=90` and `php artisan schedule:work` are the equivalent non-Docker processes.

Never put service-account JSON, private keys or registration/cleanup tokens in source control or logs. Inspect aggregate reminder/attempt statuses, queue depth and failed jobs; `accepted` means provider acceptance only. Keep API/provider request bodies out of monitoring.

`php artisan push:status` reports aggregate reminder and attempt counts without account IDs, tokens or payloads.

For Railway's existing dedicated worker, set `RUN_QUEUE_WORKER=false` on the API; the API runs the single scheduler while the queue service retains its worker start command. Standalone Docker deployments default to running both.

`php artisan ops:status --verify` checks a recent scheduler heartbeat and prints only aggregate/non-sensitive readiness information. `php artisan ops:queue-probe --wait=20` queues a harmless database/default job and waits for a shared-cache acknowledgement. It sends no push or email and uses no account data. Both services must share their database, cache and cache prefix. A missing acknowledgement is a failed runtime check, not proof of a worker outage; inspect queue configuration and logs.

## Verification and remaining gates

Automated tests cover consent, completion, daily cap, spacing, multiple devices, overnight mood rollover, streak expiry, revocation, rotated cleanup credentials, changed schedules, DST repeated hours, provider response classification and missing credentials. Flutter tests cover prompt timing, routing, legacy Hive upgrades and metadata reopen persistence.

Remaining external acceptance: Firebase projects/credentials, live Android delivery, real MySQL concurrent-worker verification, GitHub iOS build, sideload tests, and later signed iPhone APNs delivery. No deployment or real-device delivery is claimed by the implementation.

Local verification on 2026-10-12: 532 backend tests passed (3,618 assertions); the full Flutter suite passed 552 tests with one skip, and the final notification/core regression run passed 170 tests with one skip. The Android debug APK built successfully. Workflow YAML parsing and minute-scheduler registration were verified. Subsequent small scheduling adjustments are covered by the focused push suite.
