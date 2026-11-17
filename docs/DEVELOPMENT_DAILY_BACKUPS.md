# Development daily recovery copies

Owner approval: daily 03:00 Africa/Lagos (02:00 UTC), seven-day retention,
fourteen-day deletion receipts, and a normal maintenance budget of three minutes.
The approved low-volume usage estimate is approximately $0.10/month extra, not a
guaranteed cap. Production, old Railway backup entries and subscription settings
are excluded. No new always-on service is required.

## Runtime and activation

The existing API supervisor already runs the scheduler. Worker runs only its
existing queue command. Keep that arrangement; do not add another scheduler.
The native export client is Oracle MySQL 9.7.4 from its signed Debian repository;
the build verifies the published release-key fingerprint. Client dependencies
include server-core files, but no database daemon is configured or started in the
application container. Public CA/hostname verification remains mandatory for live
exports. The isolated CI fixture alone uses its disposable loopback test database.

Set `APP_MAINTENANCE_DRIVER=cache` and
`APP_MAINTENANCE_STORE=development_backup` on API and worker. The dedicated store
uses existing cache/cache_locks tables and its own shared prefix; unrelated cache
prefixes are preserved. Code defaults both backup switches to false.

During a brief controlled development pause, activate both services' deletion
ledger with the same actual UTC `DELETION_LEDGER_ACTIVATED_AT`. Enable
`DELETION_LEDGER_ENABLED=true` and `DEVELOPMENT_BACKUP_ENABLED=true`. Verify both
services' effective configuration before resuming activity. Do not backdate
coverage. Keep `DEVELOPMENT_BACKUP_SCHEDULED=false` until the first copy verifies.

```sh
php artisan development:backup
php artisan development:backup --execute
php artisan development:backup --verify=BACKUP_UUID
```

The first command is a dry run. The explicit execute command pauses development
through the shared maintenance state, waits for tracked HTTP/CLI work and reserved
queue jobs to finish, and exports a transaction-consistent SQL copy plus private
bucket files. No manual database/bucket mutations should run during this window.
Only after the first measured result is accepted within the estimate envelope,
set `DEVELOPMENT_BACKUP_SCHEDULED=true` on the API scheduler service.

## Consistency, encryption and expiry

Each copy has a random UUID under `private/development-backups/v1`. The object
inventory excludes that backup prefix and the independent deletion ledger.
Content is encrypted using libsodium XChaCha20-Poly1305 secretstream, with explicit
final frames. SQL is compressed before encryption. Each backup's random key is
inside its authenticated Laravel-encrypted manifest, protected by the existing
APP_KEY. Retain that key independently of database snapshots and never rotate it
as part of this rollout. No key or plaintext dump is placed in logs or arguments.
Temporary client credentials are mode 600, in an operation-owned mode-700 folder;
temporary archive files contain ciphertext only and are removed on completion.

Uploads are read back, authenticated, decompressed and checksum-verified. The
encrypted manifest is written last. Partial copies have no valid completed
manifest and are not accepted for recovery. The current archive-size envelope is
25 MiB total, with a 100 MiB plaintext per-object limit; growth beyond that stops
the job for a cost/feasibility review. HTTP storage requests have bounded timeouts.

Only a newly verified copy permits pruning completed copies whose snapshot time
is at least seven days old. Cleanup is confined to this feature's UUID namespace;
old Railway snapshots and independent deletion receipts are not pruned by it.
An unremovable partial copy is logged for scoped operator cleanup.

Maintenance has an ownership nonce and deadline. Cleanup compares the exact
stored payload before removing it, preserving operator maintenance that replaced
the backup state. On export/storage/size/drain failure, no new copy is reported
complete; prior copies remain. The scheduler watchdog runs during maintenance,
and HTTP middleware before Laravel's maintenance check releases overdue
backup-owned state on the first request. Database/platform failure can still
prevent recovery; the three-minute budget is not a platform-availability SLA.

HTTP/short CLI activity markers have no inferred expiry. A crashed request or
command may leave a marker, causing later backups to refuse rather than guess
that writing stopped. Pause activity, verify the specific operation is gone and
review its dedicated cache marker before operator cleanup; never flush unrelated
cache or remove an unconfirmed active marker.

## Recovery

Restore only into an isolated development instance, with external queue workers
stopped. The SQL copy retains cache-table schemas but omits transient cache and
lock rows. It adds a permanent `development_restore` maintenance marker, which
the backup watchdog does not remove. Reconcile deletion receipts after the real
snapshot timestamp and verify private-file cleanup before deliberately reopening.
Follow `DEVELOPMENT_DELETION_LEDGER.md`; snapshots before ledger activation are
not covered. Never restore receipts backwards with the database.

The manifest contains the SQL/object mappings and authenticated stream metadata.
`BackupCipher::verify` accepts an optional plaintext consumer for controlled
operator recovery in private staging, after initial integrity verification. The
hosted native fixture demonstrates decryption and SQL import against disposable
MySQL, including binary/JSON values and the closed-restore marker. It is not a
full Railway disaster-recovery drill; those additional drills remain deferred.

Commands output only operation IDs, aggregate sizes/counts and status. Record the
first real copy's duration/size and the next scheduled time in the handoff. Do not
claim inbox delivery from a form HTTP response or physical-phone acceptance from
server tests; those are separate evidence.
