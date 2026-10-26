# Admin moderation and student-review development changes

## API contract changes

- `GET /api/v1/admin/content/packages/{id}` returns `{data: package}` with decoded features and the current optimistic-concurrency version. Requires the existing `admin:subscriptions` management credential; the middleware's package override remains authoritative. Missing IDs return 404. Responses are private/no-store. No new writes or billing activation.
- Direct publish/remove of posts/comments now resolves pending reports for that exact target in the same transaction, including resolution timestamps and audit actions. Existing resolved reports, other targets, pending-only edits and rejected stale writes are unchanged.
- Student submissions remain immutable. An existing pending submission or still-active approved eligibility returns 409 on another submission; the two-submissions-per-day throttle remains in place. Rejected/expired applicants submit fresh evidence through the existing POST route. Eligibility is annual; no store purchase is activated by review.
- Student evidence is available only while pending and before the earlier effective retention deadline, capped at 14 days from submission. Approval/rejection revokes access immediately and attempts physical deletion. The review response adds `evidence_deleted: boolean`; false means a durable retry is still required, not a successful erasure claim. The encrypted path remains until deletion succeeds. Account deletion retains its existing durable private-file cleanup.
- `GET /api/v1/admin/student-applications` accepts pending/approved/rejected/expired status filtering and adds `evidence_available` and `evidence_cleanup_pending` to each application. It remains subscriptions-scoped and private/no-store. Review reasons are user-visible; they must not contain copied evidence or private support notes.
- `GET /api/v1/student-verification` expires stale pending submissions and selects the current application, including same-second resubmissions. Decision records/reasons remain after evidence deletion. Evidence expiration is recorded as a system event. Email confirmation cannot redeem a token from an expired/reviewed application.

## Operations and boundaries

`launch:prune` runs every five minutes through the existing scheduler and returns failure if physical cleanup still fails. It handles legacy longer deadlines using the 14-day cap. The request path also expires an owner's stale application, so a delayed scheduler does not preserve download access or block resubmission. Physical erasure requires working private storage and the scheduler; backup deletion is not established by these checks.

Student review remains behind its existing feature flag. This change does not enable paid billing, activate Student subscriptions, verify providers, create institutions or configure monitoring. The full approved work register is in the parent workspace's `docs/APPROVED_DEVELOPMENT_IMPLEMENTATION_2026-10-26.md`.

The repository's older OpenAPI/Postman coverage does not currently include these admin/student route families. This document records the changed contract; the wider contract-drift audit remains open and is not claimed fixed.

## Verification

Regression tests reproduce the missing package route and unresolved-report behavior before repair. API tests cover scoped access, stale writes, report isolation, annual approval, locked applications, resubmission, 14-day expiry, immediate deletion and failed-delete retry. Use the complete Pest suite with GD/cURL and other CI prerequisites. MySQL bootstrap/isolation checks must be recorded separately from SQLite tests. No production acceptance is implied.
