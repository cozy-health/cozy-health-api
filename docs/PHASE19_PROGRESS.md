# Phase 19 implementation

Updated 2026-10-13. Implementation spans the Laravel API, Flutter app and provider dashboard. Changes are in the workspace; this phase has not been deployed.

## Implemented behavior

- Catalogs require active, approved definitions with questions, options, timeframe, bands and disclaimer. Immutable version snapshots preserve history. Publication hashes a fresh database snapshot, preventing database defaults from creating a new version on later reads. Revoked versions cannot silently republish.
- All four instrument structures are supported: PHQ-9 (9), GAD-7 (7), PSS-10 (10, reversed option scores on items 4/5/7/8), and custom sleep (8). Synthetic fixtures exercise these structures without claiming clinical approval.
- The app caches definitions in account-scoped encrypted storage, shows a disclaimer before every attempt, saves a stable UUID and answers durably, and labels offline scores provisional. The server recalculates scores, interpretations, maximum score and support flags. Canonical responses replace local results before queue acknowledgement.
- Transient failures retain queued work; terminal validation failures retain answers with a rejected state. Deletion produces a server tombstone, including attempts deleted before their first upload, so delayed sync cannot restore them. Existing legacy records remain readable; only complete compatible PHQ/GAD answers can be upgraded through validation.
- Charts use completion dates, separate definition versions and scales, label provisional points, and support reduced motion and screen-reader descriptions. Pending and rejected records are distinguished from validated history.
- Owners can share one validated result with an exact-username recipient or their currently linked active, verified provider. Provider category consent and a selected grant are both required. Provider reads are audited; consent withdrawal, unlinking, verification withdrawal, deletion and grant revocation remove access. Report downloads recheck their recorded grant revisions.
- Nonuser invitations contain a random token stored hashed on the server, expire after seven days, and allow one explicit claim after authentication and onboarding. Retries cannot recreate a revoked grant. The landing page exposes no result; pending links are encrypted on the device. Newly installed users reopen the original link.
- Friend and provider inboxes paginate. Recipients see result summaries without raw answers. Dismissal removes a result from the friend inbox; ownership and grant revocation remain separate. Screens clear sensitive memory on session changes, and delayed responses cannot repopulate a signed-out screen.
- Owner PDF export uses an authenticated endpoint and a temporary mobile file with cleanup. Exported copies cannot be recalled. Share notices use generic text, consent and quiet-time checks, device reservations, and the reminder system's spacing/daily allowance; uncertain deliveries are not replayed.

## API and database

Authenticated patient routes are under `/api/v1/quizzes`: catalog/detail/version, online start/submit, offline submit, owner attempts, exact recipient resolution, friend/provider grants, invitations/claim, received inbox/detail/dismissal and owner PDF. `/quiz-attempts` and `/sync/batch` use the same server validation and deletion rules. Provider routes are read-only `/api/v1/provider/quiz-shares` and `/quiz-shares/{id}`.

Three migrations add immutable `quiz_definitions`, attempt validation/snapshots, selected grants and invitations, provider report authorization snapshots, canonical username keys and quiz push reservations. The username migration preflights case/whitespace-normalized collisions before changing schema. Resolve any reported collisions deliberately before retrying an upgrade. New handles require 3–50 ASCII letters, digits or underscores; unchanged legacy handles are preserved.

For a disposable local database only:

```powershell
php artisan migrate
php artisan db:seed --class=AssessmentFixtureSeeder
```

`AssessmentFixtureSeeder` is excluded from the default seeder and refuses non-local/non-testing environments. Production requires the approved wording, scoring bands, recommendations, timeframe, disclaimer and documented instrument permissions to be entered into the existing quiz/question/option records before setting approval. Changing an approved definition creates a new immutable version; historical snapshots retain their prior meaning.

The scheduled `quiz:cleanup` task removes old expired unclaimed invitations and temporary quiz sessions. Existing scheduler and queue operation are required. The `quiz` feature flag pauses new attempts without discarding saved answers.

## Verification

- Full API regression: **556 passed, 3,735 assertions**.
- Phase 19 against SQLite: **18 passed, 102 assertions**, including canonical username collision rejection through profile updates and sync.
- Phase 19 against an isolated MySQL 9 container: **18 passed, 102 assertions**, including populated username upgrade, collision preflight and rollback.
- Flutter analysis: no errors or warnings; one existing style information notice in `lib/core/storage/hive_files_io.dart`.
- Dashboard: **16 unit tests passed**, lint, TypeScript and production build passed. HTTP and Chromium integration passed, including selected-result reading and revocation refresh.
- Full maintained Flutter regression: **576 passed, two existing skips**. User-created audit probes are excluded from the maintained suite. Widget regressions include pagination, delayed responses after logout and deletion of results passed as navigation extras.
- Final quiz-only regression after the additional sharing session guards: **49 passed**.
- Android debug APK build passed with the new native dependencies and link manifest. Output: `cozy-health-mobile-app-main-main/build/app/outputs/flutter-apk/app-debug.apk`. Existing Gradle/Kotlin support notices, SDK XML mismatch and plugin deprecation warnings remain; this build does not establish signed-release or physical-device acceptance.

Tests cover authoritative scoring, reverse scores, canonical sync, tombstones, account boundaries, revoked definitions, claim retry behavior, provider verification and audits, report invalidation, owner-only PDFs, push idempotency, consent, quiet times, daily limits, encrypted caching, per-attempt disclaimers and recipient screen clearing. API/dashboard fixtures contain synthetic data only.

## Release gates

1. Obtain clinician review and instrument permissions for real content, including the custom sleep interpretation. Engineering fixtures must never serve as production clinical content.
2. Configure `QUIZ_ANDROID_PACKAGE`, `QUIZ_ANDROID_FINGERPRINTS`, `QUIZ_IOS_APP_ID`, `QUIZ_ANDROID_STORE_URL` and `QUIZ_IOS_STORE_URL` for the release applications. The current Android package defaults to the repository's development package. The HTTPS association endpoints return 503 until their required signing identifiers are supplied.
3. Verify `/quiz-invite/*` on the configured host in Android App Links and iOS Universal Links, including cold start, signed-out onboarding, expired/revoked claims and install-then-reopen. No Android/iOS device is connected in this Windows workspace.
4. Verify native PDF/share-sheet behavior, cancellation and temporary-file cleanup on devices. iOS compilation and signing require macOS. Confirm existing network pin, legal-document and platform signing gates from earlier phases.
5. Keep real push delivery subject to the Phase 18 acceptance gates. The tests verify scheduling/delivery contracts with fake transport, not real remote-device delivery.

Do not treat automated results as clinical content approval or as evidence of native device acceptance.
