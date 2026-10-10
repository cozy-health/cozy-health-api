# Phase 16 endpoint implementation — 2026-10-10

Status: endpoint contracts implemented; phase ship gate remains open. Local verification does not establish deployment health or complete the later feature UI phases.

## What changed

The earlier batch restored saved-content reads and notification unread counts. This continuation adds push-device registration/removal, notification preference reads, mood statistics, reviewed regional crisis resources, content discovery/search/bookmarks, quiz catalog/start/submit/share, and community text/group/report/block contracts. Existing mobile consent and session paths remain supported; the build plan's `/user/consents` and `/user/sessions` names are aliases to the same controllers.

All patient endpoints require the patient Sanctum guard and active-patient middleware. The contract manifest verifies methods, target actions, authentication and rate limits before resource lookups. Resource ownership/visibility rules are tested separately. Response envelopes retain `data`, including paginator `data.data`, `last_page` and `total`. Backend list projections were not narrowed.

## Core contracts

| Method/path (under `/api/v1`) | Request | Response / rules |
|---|---|---|
| POST `/notifications/register-token` | `device_id`, `platform` (`ios`/`android`), `provider` (`fcm`/`apns`), `token`; optional `environment` (`production`/`sandbox`) | Registers or rotates that patient's device. APNs tokens require 64 hex characters and iOS. Tokens are encrypted at rest and hidden from responses. Global token hashes prevent silent reassignment to another account/device; conflict returns 409. Registration expiry is 90 days. Delivery is Phase 18. |
| DELETE `/notifications/devices/{deviceId}` | None | Removes only the caller's registration; idempotent. Sign-out token unregistration and delivery cleanup still need Phase 18 mobile wiring. |
| GET `/notifications/preferences` | None | Current notification preference fields. |
| PATCH `/notifications/preferences` | Existing preference fields | Uses the existing profile preference implementation. Reminder time is validated as `HH:mm`. Existing `/user/notification-preferences` remains available. Scheduling is Phase 18. |
| GET `/mood-entries/stats` | Optional `days`, 1–365, default 30 | User-local inclusive date range, count and mood breakdown, average **intensity**, never a happiness or diagnostic score. Excludes soft-deleted, logically deleted, other-account and future-day records. |
| GET `/crisis/resources` | Optional uppercase two-letter `country_code` | Active, reviewed, verified resources matching the selected/stored country plus explicitly global `ZZ` records. Verification must be within six months. Missing country is reported; timezone is not used to guess location. Empty results are explicit. |
| PATCH `/user/profile` | Optional `country_code` (uppercase two-letter code or null) | Saves the user's region choice; retains existing profile fields and timezone behavior. |
| GET/POST `/user/consents` | Existing consent payload | Alias of `/me/consents`; existing document/version enforcement remains unchanged. |
| GET `/user/sessions`, DELETE `/user/sessions/{id}` | Existing session contract | Aliases of the existing `/me/sessions` implementation. |

No real hotline numbers were invented or seeded. Owner-reviewed regional records are still required before this endpoint can supply launch-ready resources. The six-month freshness policy is an implementation rule that the resource owner should include in their maintenance process.

## Content contracts

- `GET /content/articles`: paginated published articles, optional `category`, `q`, `page`, `per_page` (1–100). Categories: anxiety, depression, sleep, relationships, self-care and work.
- `GET /content/articles/{slug}`: published article detail; accepts article UUID as well for caller compatibility.
- `GET /content/categories`: categories with counts of visible articles.
- `GET /content/search?q=...`: literal title/excerpt search. `%` and `_` do not turn into wildcard requests. Results use the same publication and pagination rules.
- `POST/DELETE /content/articles/{id}/bookmark`: idempotent save/remove. Existing `/save` callers are aliases to the same behavior. References may be UUID or slug.
- `GET /content/bookmarks`: alias of `/content/saved`, preserving the existing saved-record model and paginator.

Article publication requires approval, a nonempty license and a publication timestamp no later than now. Draft, future and deleted articles are not exposed. No reading events or personalized rankings are added. Bookmarks snapshot article body and metadata for private offline cache use; removing a bookmark remains possible after its source article is unpublished. Re-saving a soft-deleted bookmark restores its original record ID.

The mobile SavedArticle Hive adapter now persists slug/category/read time/body, while retaining the six existing fields and type ID. New and legacy cache reopening tests pass. The saved-content reader keeps pagination/account/merge guards and now bounds page counts. Direct save/unsave methods use canonical bookmark paths; unsave also removes its local record. Five unused activity endpoint constants were removed after confirming they have no references. Payment/future repository methods were retained as required by the deferred-work notes.

This does not replace the existing static article screens/catalog. Real feed/search rendering and cached-body display remain Phase 20 work. Clinician-reviewed, licensed articles must be provisioned separately.

## Quiz contracts

- `GET /quizzes`, `GET /quizzes/{slug}` expose only active, approved definitions with scoring rules and a disclaimer. Details eagerly load ordered questions/options.
- `POST /quizzes/{id}/start` requires `disclaimer_accepted: true`. Returns a server-generated `attempt_id`, expiry (seven days) and a definition. The session snapshots the approved questions, options and scoring rules.
- `POST /quizzes/{id}/submit` takes `attempt_id` and `answers`, an ordered list of integer **option IDs** corresponding to the question order in the returned definition. Every question must have one valid option. Client-supplied score, interpretation or crisis flags are ignored.
- Scoring uses the session snapshot, not a subsequently edited option table. `scoring_rules.bands` is an ordered list of `{max, label}`; the first matching band provides the result label. Optional `crisis_threshold` is honored. The build plan's PHQ-9/GAD-7 threshold of 15 is also enforced. Revoked catalog approval blocks new submissions.
- `GET /quizzes/attempts`, `GET /quizzes/attempts/{id}` return only the patient's completed, undeleted results. Existing `/quiz-attempts` reads remain unchanged.
- A repeated submission of identical answers returns the original result; changed answers on a completed session return 409. Deleted results cannot be recreated by retrying a completed session. Expired unsubmitted sessions return 410.
- `POST /quizzes/attempts/{id}/share` takes `provider_id` and `consent: true`. It requires an active, verified provider link, an active/verified provider, existing `consent_quizzes`, and ownership of the result. It records sharing intent and a provider access audit event. It does not silently grant broad consent or send to an arbitrary email address.

No clinical questions, scoring bands or clinical advice were seeded as approved. The owner/clinician must approve the Phase 19 instruments and copy. Generic response/error handling exists; the actual quiz UI, consent/disclaimer presentation, offline definition/version protocol, PDF export and validated offline sync scoring remain Phase 19 work. Legacy synced attempts are still accepted by the existing sync contract; the new server-scored route does not retroactively certify their client-generated scores.

## Community contracts and release gates

All 18 Phase 16 community paths are registered. Text posts/comments, chronological pagination, own edits/deletes, reports, bilateral blocking and group membership have real database persistence. Author IDs/names are removed from anonymous public payloads. Reports store snapshots, status and a 24-hour due timestamp for later moderation tooling. Crisis phrase detection is a conservative review signal, not a clinical assessment; flagged text enters review and the resource suggestion is returned only to its author. Edited posts enter review and cannot revive moderator-removed posts. Group content requires active group membership.

Post/comment deletion clears the stored body immediately and hides the row. Account deletion scrubs matching moderation snapshots/details before cascading owned rows, retaining minimal moderation metadata rather than the deleted account's identity/text. Current account deletion removes owned posts rather than retaining anonymized public posts; Phase 21/22 must reconcile any desired public-thread retention with the owner-approved policy before community launch. Moderation report snapshots otherwise remain subject to the plan's retention/legal review requirements.

Public community use remains disabled by default. Both the `community` feature flag and `COMMUNITY_RELEASE_READY` must permit use, with `COMMUNITY_GUIDELINES_VERSION` configured. Owner legal/moderation/escalation readiness is still required; this implementation does not authorize clearing those blockers. Posts require acceptance of the configured guideline version.

`POST /community/posts/{id}/image` validates ownership, JPEG/PNG/WebP, size below 5MB and dimensions below 4096px. **It returns 503 and persists nothing because a moderation provider/EXIF-stripping pipeline has not been configured.** This is a deliberate fail-closed dependency, not completed image upload. Phase 21 must implement the approved service pipeline and test rejection/EXIF removal before enabling image posting. Moderator actions, bans/appeals and admin queue UI remain Phase 22.

## Caller audit and verification

- `phase16-routes.json`: 51 patient route entries including compatibility aliases and prior existing routes. Not all 51 are newly missing endpoints.
- `dashboard-route-contracts.json`: 33 authenticated provider/admin bridge contracts, audited against the dashboard's request wrappers/allowlists. PDF bridge paths translate to backend report download/signed file routes rather than a nonexistent backend `/pdf` route.
- The route tests exercise all 84 entries with action matching and guard checks; patient entries also check throttling. No dashboard files were modified.
- Reachable existing mobile auth/profile/provider/consent/session/dashboard/insights/affirmation/sync/read paths have registered backend routes. Core writes use `/sync/batch`. The unused Assistant send method remains assigned to Phase 17; scripted UI does not currently call it. Payment purchase/restore methods are explicitly post-MVP, not implemented in Phase 16. These are documented exclusions from the normal-use route audit, not silently working APIs.
- No SELECT projections were narrowed. Raw presence merge/detail-fetch carryovers require a compatible shipped mobile release, not merely locally green tests.

Tests use isolated fixtures/in-memory SQLite and mock mobile transport/storage. Real MySQL concurrency, Railway no-404 logs, signed mobile builds and physical-device calls remain unverified. Coverage >70% is the following Phase 15 gate.

Local results: full backend suite 512 passed (3,462 assertions, 79.96 seconds); Flutter analysis reports no issues; full mobile suite 510 passed; dashboard unit suite 16 passed. PHP syntax checks and Pint pass on changed PHP files. Dashboard tests emit the existing Node module-type warnings but pass; no package configuration was changed for this backend work.

## Migration / release handoff

Repository-wide Pint still reports formatting violations in untouched legacy files. The 27 changed/new PHP files pass Pint and syntax checks; the broader formatting debt is flagged rather than included in this endpoint batch.

Three additive migrations create core contracts, quiz sessions/shares and community storage. They add nullable fields to users/quizzes/saved articles and leave existing records valid. Existing quizzes/articles/resources are not automatically treated as approved. Apply these through the normal release pipeline; no production migration was manually run in this session.

Do not enable community or publish fixture data just to make a smoke test green. Keep failed/disabled features explicit in the later mobile integration. Phase 16 cannot be marked shipped until its remaining image/deployment and live verification requirements are resolved.
