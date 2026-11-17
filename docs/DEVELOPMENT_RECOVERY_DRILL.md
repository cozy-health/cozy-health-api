# Development recovery drill

Run `scripts/drill-development-database-restore.sh` only in the development MySQL
service identified by its project/environment guards. It never replaces the live
database and never uploads a database dump outside that service.

The drill requires a populated, bounded InnoDB database and sufficient free disk
space. It refuses views, stored routines, events, triggers and nontransactional
tables because those require a separately reviewed restore procedure. It takes a
consistent single-transaction snapshot into a private mode-700 task directory,
restores it twice into randomly named `e08_restore_` schemas, and compares all
schemas, populated row counts and non-null table checksums. It removes only its
own schemas, dump and temporary comparison files on completion or failure.

On 8 November 2026 the final populated-data drill restored 117 tables containing
1,784 rows; all schemas, row counts and checksums matched in 17 seconds. These
are measured drill results, not an approved recovery-time or recovery-point SLA.
An earlier attempt stopped because `cmp` was absent; the tested implementation
uses the already-installed OpenSSL hashing tool without weakening comparisons.

Private object-storage verification separately proved authenticated/signed access,
unsigned denial, encrypted canary recovery and removal. It did not certify the
provider's at-rest encryption: its object metadata did not report SSE. Vendor
assurances and agreements remain an operational review dependency.

## Before reopening any restored application

Keep the restored instance isolated. Reconcile deletion requests newer than the
snapshot, replay the authorized deletions and associated private-file cleanup,
verify user/provider access restrictions, and only then reopen application access.
Do not resume restored queued mail or external jobs against real recipients during
validation. Preserve encryption keys needed by stored data and queued payloads.

A durable deletion ledger outside the database and approved backup/ledger retention
window are still required to make that replay reliable after loss of the original
database. The measured restore drill does not close that gap. No backup-retention
or deletion policy is invented by this document; the owner has been asked to choose
the development backup window before the ledger/schedule is implemented.
