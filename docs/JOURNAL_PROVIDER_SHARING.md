# Written journal sharing with a provider

This additive API supports an explicitly reviewed copy of one saved written entry for one active connected provider. The original journal remains private; the existing `journals` record type still returns metadata only. Audio and automatic sharing are unsupported. The mobile/provider visual integration is awaiting the owner's revised-screenshot approval; these endpoints do not make the existing placeholder journal button functional by themselves.

## Contract

Patient base: `/api/v1/provider-conversations/{linkId}/journal-shares`.

- `GET /preview?journal_id=UUID`: returns `journal_id`, opaque `revision`, chosen `provider` (`id`, `name`), `entry` (`title`, `body`), and `later_edits_shared=false`. Requires an owned saved written entry and current category consent. Preview does not send anything.
- `POST /`: body `{journal_id, expected_revision, client_id, confirmed:true}`. The server compares the preview revision against its current title/text under a lock, then atomically stores an encrypted snapshot and a conversation message. Returns `{share_id,replayed:false}` with 201, or the same share with `replayed:true`/200 on an identical retry. Changed entry/version, conflicting request ID or retry of a withdrawn copy gives 409. The caller must obtain explicit fresh confirmation before changing the request ID.
- `GET /{shareId}`: owner-only patient access to `{share_id,entry:{title,body},shared_at,later_edits_shared:false}`. The chosen provider reads the same representation through `/api/v1/provider/conversations/{linkId}/journal-shares/{shareId}`.
- `DELETE /{shareId}`: owner-only, repeatable withdrawal. Purges the stored title/text; the existing message becomes an unavailable-record placeholder. Withdrawal remains possible after link/category revocation.

All full-text responses set `Cache-Control: private, no-store`. Unauthenticated requests return 401; disabled sharing returns 403; foreign/unavailable entry, recipient or share returns 404; unsaved/draft/voice/empty entries and missing explicit confirmation return 422.

Conversation messages contain a server-created `journal_shares` reference with `requires_online_review=true` and a generic label. They never embed snapshot title/text, so older clients' durable message caches cannot capture the text. New clients must fetch it online for the selected recipient, display it in a non-persistent view and clear temporary content on session changes, withdrawal and lost access. Do not put the text in notification payloads, browser storage, analytics or logs. The normal message write endpoint rejects forged `journal_shares` references.

## Lifetime and compatibility

Private edits never update previous shares. Sharing the edited version needs a fresh preview and confirmation. Category withdrawal, disconnect/reassignment, journal direct/sync deletion or account deletion purge all affected snapshot content; reenabling permissions/restoring a deleted record cannot revive those copies. Deleting an account physically cascades share rows. Existing retention/backup erasure obligations still apply; this does not claim to erase anything a recipient has already read or copied.

Provider access auditing records identifiers/actions without journal text. No new push/email delivery is triggered by the feature; the shared entry appears in the existing conversation. No public/community sharing is added.

Tests cover owned/foreign/disabled actors, explicit confirmation, encrypted storage, revision conflicts, safe retries, immutable copies, legacy metadata restrictions, forged references, rollback, withdrawal, reenable/restore and direct/sync/account deletion. The full suite and MySQL isolation/journal-sharing contract are required before deployment readiness is reported. Use existing develop branches and the approved development Railway environment only.
