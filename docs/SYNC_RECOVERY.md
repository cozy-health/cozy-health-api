# User sync recovery — 2026-10-10

## Faults and fixes

- A 401 during batch upload could deadlock: the auth interceptor awaited guest activation, which awaited that same unfinished upload. Account switching is now deferred until the request completes, serialized and conditional on the session still being expired. Drafts remain in their original encrypted account cache; relogin and stored-session resume immediately retry pending work.
- Uploads previously inspected the queue once. New edits made during an upload now drain in subsequent passes, without retrying failed operations endlessly in one run. New intent replaces older queued intent; existing duplicates are compacted by timestamp. Removed in-flight predecessors cannot reappear on failure.
- Profile, safety-plan and subscription records are one-per-user, but local/server IDs can differ. The writer resolves the owned singleton, preserves its canonical ID and serializes account writes. Cancellation resolves the owned subscription. Ownership checks remain in force.
- Legacy saved bookmarks can omit non-null slug/category/read-time columns and use saved_UUID IDs longer than MySQL's 36-character ID column. Missing metadata uses existing values or explicit empty/zero fallbacks; overlong new bookmark IDs get UUIDs. Bookmark deletion accepts the mobile article ID; explicit resave restores the same saved record.
- Null conversation titles receive a neutral default. Stale timestamped updates are acknowledged without overwriting a newer value; stale updates do not revive deleted records. This is timestamp conflict handling, not a cross-device revision protocol.
- Batch responses include safe error categories. The mobile queue stores only allowed categories, never server SQL text or validation content. Auth, access, connection, server, secure-connection and invalid-payload problems have distinct user messages.
- Initial online state triggers an upload. The pending sheet closes after a successful retry and provides Close after a failure while preserving unsynced changes.
- Saved-article background refresh is implemented. Notification read/read-all changes and safety-plan resets now queue durable uploads. Notification/safety-plan downloads use account and pending-intent merge guards. Safety-plan cache keeps one current canonical record. Notification read time/deep link now survive encrypted cache reopening with backward-compatible optional fields. Profile parser accepts decimal text-size strings from the database.

## Contract verification

The mobile's real serializers generate tests/Fixtures/mobile_sync_operations.json using synthetic data. API compatibility tests consume that exact file and assert persisted rows, repeat-upload idempotency, canonical singleton identity, legacy bookmark behavior, stale-update protection, deletion tombstones and safe failure envelopes.

| Queued type | Upload contract |
|---|---|
| mood_entry | Mood fields, arrays, crisis boolean and client timestamps |
| journal_entry | Body/type, draft and client timestamps |
| chat_conversation | Title/default, archive/count and update time |
| chat_message | Text and owned conversation dependency |
| safety_plan | Plan arrays, completion and update time; owned reset |
| user_profile | Owned singleton identity and display/notification settings |
| user_preferences | Normalized onboarding labels and completion |
| quiz_attempt | Existing offline attempt contract |
| saved_article | Legacy/current metadata, article identity and bookmark removal |
| app_notification | Stored notification data, read state and removal |
| subscription / subscription_status | Existing singleton contract and cancellation |

Full backend suite: 521 passed, 3,576 assertions. Mobile full-suite and analysis results are recorded in the mobile report after final isolated verification.

## Limits

No Android device was connected. The reported phone's installed build and its live authenticated database round trip remain unverified. Tests use encrypted temporary mobile storage/mock HTTP and in-memory SQLite, not production MySQL concurrency. Valid serializers and explicit legacy cases are covered; malformed records remain queued with a failure category rather than being discarded to claim success.

Demo mode deliberately never uploads demo edits. Guest data stays separate from existing-account login. Release/profile builds still require the approved certificate-pin configuration; verification was not disabled. Future assistant/community/feed integrations, image moderation and payment receipt verification retain their build-plan phases. A supported sync envelope does not make those future features complete or clinically certify legacy quiz scores.

No clinical content was changed, and no production data/migration was manually modified. Separate Home design edits in the shared workspace are excluded from the sync commit.
