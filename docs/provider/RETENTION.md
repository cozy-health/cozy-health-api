# Provider retention and deletion policy — draft

v0.1, 2026-10-09. Policy owner: product/privacy lead. Counsel and clinical approval
pending. Automatic provider destruction is disabled by default. No production
prune run or retention approval has been performed.

| Data | Proposed policy / current behavior | Implemented destruction |
| --- | --- | --- |
| Routine provider access events | Two years proposed; counsel must approve or replace by jurisdiction | `provider:prune` supports configured days; none configured by default |
| Consent/link/revocation evidence | Separate evidentiary period; shared plan proposes seven years, subject to counsel | Explicitly excluded from routine pruning; no approved schedule |
| Notes, assignments, referenced PDFs | Clinical/record-set periods must be defined per practice; withdrawal blocks access, not automatic erasure | No automatic age-based pruning until approval and implementation |
| Orphan PDFs | Already-unreferenced blobs; optional cleanup after 24-hour safety margin | `provider:prune --orphan-reports`; only generated UUID paths without report rows |
| Dashboard credentials | 30-minute absolute validity; logout/password reset revoke credentials | Sanctum rejects expired credentials; use documented Sanctum expiry cleanup separately |
| Provider password-reset codes | Short expiry enforced by auth controller | Expired-row storage cleanup is a separate operational task |
| Patient account deletion | Current controller immediately force-deletes user and cascaded rows | Existing account API; not a 30-day grace period |
| Provider account deletion | No approved lifecycle process; database cascades can remove audit evidence | Define lawful export/holds/deletion before offering account destruction |
| Backups and external downloads | Not affected by database/PDF pruning; provider-held copies may have independent duties | Operations must define backup expiry and restore-time deletion reconciliation |

The command deliberately does not invent a universal clinical retention window.
It preserves consent/link events (including unknown future actions), unregistered
provider evidence, held provider IDs, referenced PDFs and other storage paths.
Audits contain identifiers and request metadata: do not retain them indefinitely
without an approved basis; implement the separate evidence lifecycle after review.

## Runbook

1. Counsel approves an explicit schedule, applicable jurisdictions, record-set
   boundaries, account-deletion exceptions, backup rules and legal-hold process.
2. Record the signed version in `PROVIDER_RETENTION_APPROVAL`. Set
   `PROVIDER_ACCESS_LOG_DAYS` to the approved integer (e.g. 730 only if approved).
   Configure `PROVIDER_RETENTION_HELD_PROVIDER_IDS` with comma-separated provider
   IDs, or `PROVIDER_RETENTION_GLOBAL_HOLD=true` for a global incident/legal hold.
   IDs cover all records of the held provider. Use a global hold for invitation
   evidence or investigations whose scope is not yet known.
3. Refresh Laravel config cache after changes. Export necessary records securely,
   check the storage volume/backup policy and run `php artisan provider:prune`.
   It previews counts, with no patient identifiers or note bodies in output.
4. Optionally preview `php artisan provider:prune --orphan-reports`. A file must
   match the generated provider/UUID PDF path, be older than 24 hours, have no
   database reference, and not belong to a held provider. This handles leftovers
   from cascaded account/report-row deletion and failed generation. Never run it
   during database recovery/import; references may temporarily be missing.
5. After reviewing counts and approval, use `--execute` (plus `--orphan-reports`
   only when intended). Without an approval reference it fails closed. Repeat is
   idempotent. Logs are deleted in batches of 500; failures may leave partial work
   and can be retried. A shared cache lock prevents overlapping runs for one hour;
   terminate/investigate a run exceeding that window before another execution.
6. Capture aggregate counts, timestamp, operator and approval version in the
   restricted operations record. Verify recovery/restore procedures without
   reintroducing deleted records. No automatic schedule is installed in PD-6.

Use a shared cache for multi-instance execution. Holds/config changes must be
coordinated with a stopped prune run; a mid-run change does not reload config.
The 24-hour orphan margin assumes generation transactions finish within that
window; failed/recovered transactions require an operations hold.

## Required follow-ups before external onboarding

Approve and implement clinical-record and consent-evidence lifecycle; resolve
account-deletion cascades versus proof preservation; define backup deletion and
provider-held-copy obligations; ensure provider PDFs survive redeploys on private
durable storage. The current Railway service exposes no report-storage volume.
This command has been tested only against isolated storage/database fixtures.
