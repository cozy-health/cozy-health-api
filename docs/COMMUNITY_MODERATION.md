# Community restrictions and appeals

Development implementation approved 13 November 2026. A single existing admin with `admin:moderation` may issue restrictions and decide appeals, including their own decisions. No new admin is provisioned, no public response-time promise is made, and production activation is not authorized.

## Behavior

Restrictions last 24 hours, 7 days, 30 days or until lifted. They affect community participation only: posts/edits, comments, reactions, joining and image uploads are rejected server-side. Reading, reporting, blocking/unblocking, leaving, removing one's own content and appeals remain available. Existing content and other app features are not removed or disabled by a ban. Expiry stops enforcement immediately; the every-minute scheduler records expiry and queues its notices.

The community status screen lists owned restrictions/content decisions and appeals. Admin content removal creates an appealable decision without exposing reporter identity. One appeal is persisted per decision; duplicate retries recover it. Appeals require 5–2000 characters. Decisions and lifts require reasons of 5–1000 characters. The admin queue supports paginated decisions and pending appeals, current content links and expected-version checks.

Accepted ban appeals lift that ban. Accepting removed-content appeals requires explicit republishing confirmation and an unchanged, existing target; edited, deleted or superseded content cannot be restored by a stale appeal. Rejected appeals leave restrictions/content unchanged. Reasons/outcomes are shown to the affected user. Submitted appeal text and review outcomes are encrypted at rest; account deletion cascades these user-owned rows and queued notice payloads.

## Interfaces

- `GET /api/v1/community/moderation`: owned active ban, latest decisions and appeal status. Available without paid participation.
- `POST /api/v1/community/decisions/{id}/appeal`: `{message}`; owned, one per decision, idempotent.
- `GET /api/v1/admin/community-moderation`: decision `page`, `per_page`, optional `user_id`; separate `appeals_page`.
- `POST /api/v1/admin/community-moderation/bans`: `{user_id,duration,reason}`, duration `24h|7d|30d|permanent`.
- `POST /api/v1/admin/community-moderation/bans/{id}/lift`: `{expected_version,reason}`.
- `POST /api/v1/admin/community-moderation/appeals/{id}/review`: `{expected_version,decision,reason,confirm_republish?}`, decision `accepted|rejected`.

All admin operations require the existing management session/capability. Conflicts return 409 and must be refreshed; missing republishing confirmation returns 422. Standard session/CSRF/origin, role, ownership, feature-readiness and request limits remain enforced. OpenAPI, the operation ledger and saved Postman requests describe the additions. Fixtures must not apply bans to real people.

## Notices and delivery

Ban creation/lift/expiry, content removal, appeal submission and appeal decisions create deterministic in-app notifications and durable encrypted email outbox entries. `community:moderation-notices` runs every minute; email jobs use the existing email queue, branded mail and delivery ID. Duplicate workers serialize on the account/outbox row. Transport failures are recorded and retried after five minutes, up to three attempts. Deleted/inactive accounts and changed, unverified or invalid addresses are suppressed; in-app status remains authoritative. Existing mail allowlists/suppression are preserved. External SMTP acknowledgement is not proof of inbox arrival, and a process crash after remote acceptance cannot guarantee external exactly-once delivery.

## Validation and delivery

Feature tests cover restrictions, durations, expiry, ownership, duplicate bans/appeals/reviews, stale/deleted content, confirmation, preserved access and notification retry/recipient checks. Guarded disposable MySQL CI covers concurrent bans, appeals, reviews, notice delivery and participation. Browser fixtures cover creation/lift, stale recovery, explicit confirmation, 320px/200% layout and revoked permissions. Mobile widget checks cover retry draft preservation, one-appeal UI and both themes at large text.

No paid access is granted, no new mailbox is configured and no real notification is sent by these tests. Exact delivery/check outcomes and remaining real-device/inbox acceptance are recorded in workspace `docs/HANDOFF.md` and the feature status document.
