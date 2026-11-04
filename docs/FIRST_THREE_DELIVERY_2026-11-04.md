# First-three development delivery - 4 November 2026

Scope: reuse and verify existing account/offline and sign-in foundations; add private customer support conversations and verified privacy requests. The [repository build plan](BUILD_PLAN.md) includes all fourteen areas and all 175 original requirement IDs with their current dispositions. It preserves historical scope without rebuilding completed work or reviving excluded features.

Implemented: ordered/encrypted/idempotent replies; explicit resolve/reopen; staff-only notes; generic inbox/email notifications and delivery retries; separately assigned support/privacy permissions; customer-requested export/correction/deletion with identity/version checks; authenticated cloud ZIP; existing profile/deletion behavior reused with correctly scoped model context; durable version-checked deletion jobs. Contract: [CUSTOMER_OPERATIONS.md](CUSTOMER_OPERATIONS.md). OpenAPI/Postman requests, additive migration and CI selections are included.

Local validation:

- Full SQLite API suite: **972 passed / 7,202 assertions**, PHP 8.2 with required GD and a 512 MiB process allowance. No tests skipped or assertions weakened.
- Disposable MySQL 9 release-contract selection: **268 passed / 2,200 assertions**.
- MySQL concurrency: duplicate reply retries commit once, stale distinct replies conflict, concurrent privacy decisions commit once; all three scenarios passed. The runner's first startup exposed an import-order error introduced by formatting; bootstrap now follows imports and the same runner passed.
- The container was created only for this batch, bound to loopback with `cozy_release_test`; exact ID/task label checked before removing it and its anonymous volumes. Other databases/containers untouched.

Development only. Reviewer assignment defaults remain empty; owner must name actual support/privacy staff. No enrollment, consent enforcement, payment/store or production flag enabled. Existing clients can still submit reports. Native/device/inbox/worker/cleanup and live development migration acceptance remain separate gates. Queue acceptance is not confirmed inbox receipt or completed physical deletion.

Commit/push requested by owner on 4 November. Hosted release checks must be verified against the exact resulting develop SHA. Detailed run/commit outcomes are recorded in the workspace HANDOFF; this file is part of that commit and does not invent a self-referential SHA. Unrelated documentation/archive edits are excluded.
