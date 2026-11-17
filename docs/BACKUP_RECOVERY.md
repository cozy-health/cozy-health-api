# Shared backup recovery

The same BackupService, BackupControl and DatabaseBackupDump implement database and private-file recovery across development and production. The owner approved production deployment and a bounded maintenance pause. The database cutover has one operator window of up to 15 minutes, including measured rollback time. Each backup remains bounded by 180 seconds.

## Configuration and compatibility

Use BACKUP_ENABLED and BACKUP_SCHEDULED for activation. Development deployments also accept the existing DEVELOPMENT_BACKUP_ENABLED and DEVELOPMENT_BACKUP_SCHEDULED values when the neutral variables are absent. Production defaults both flags off and ignores the development flag names.

Production backup readiness requires BACKUP_MAINTENANCE_APPROVED=true, the exact project/environment identity, APP_ENV=production, the canonical API origin, database-backed shared maintenance and verified MySQL TLS. BACKUP_STORAGE_BUCKET must match the configured private-file bucket. API and worker must use compatible configuration and keys. Credentials remain in deployment configuration.

Development keeps its existing private/development-backups/v1 object prefix, cozy_development_backup_ cache prefix and development_backup maintenance store. Production uses private/backups/v1, cozy_backup_ and backup_control. These namespaces isolate leases and archive identities while sharing implementation. Existing development manifests remain readable. Manifest verification rejects the wrong environment.

The backup still uses the existing private S3 bucket layout, seven-day archive retention, fourteen-day resolved deletion receipts, 25 MiB encrypted archive envelope, 100 MiB plaintext envelope, 10,000-file limit and daily 02:00 UTC schedule. The maintenance ceiling is 180 seconds and in-flight drain is bounded. Confirm actual data size and runtime before scheduled activation. The configured ceiling remains an abort limit.

Deletion receipts require explicit DELETION_LEDGER_ENABLED activation, a real coverage timestamp and approved deployment identity. Receipt operations require independent private object storage. Reading or writing a receipt has no maintenance-pause approval dependency. Preserve original receipt history and key custody when promoting source.

## Commands

Use backup:create for a dry run. Add --execute to create one encrypted, independently read-back recovery copy. Use --verify=UUID to verify an existing copy. backup:watchdog releases overdue backup-owned maintenance. The existing development:backup and development:backup-watchdog aliases use the same commands.

To create a backup inside an already active operator window, use `php artisan backup:create --execute --operator-window=UUID`. The shared maintenance payload must contain that UUID in `operator_window`, its start epoch in `time` and its absolute expiry epoch in `operator_deadline`. The deadline must be in the future and no later than 900 seconds after the start. A `backup_owner` field must be absent. Establish this payload through the reviewed operator cutover procedure under the shared maintenance-transition lock.

The backup acquires its own exclusive lease and uses the earlier of its 180-second ceiling, configured backup duration and operator deadline. Supplying the wrong UUID, an expired window or an overlapping backup fails. Changing the window owner or deadline invalidates the backup lease. Success, failure and watchdog cleanup preserve operator maintenance. The operator remains responsible for rollback and releasing the owned window. An expired operator window remains in maintenance until the operator handles it.

Existing native verification remains in tests/Support/verify_native_development_backup.php so CI and local harness entry points stay compatible. New dumps insert a backup_restore maintenance marker and backup_environment tag. Previously generated development_restore markers still keep restored Laravel applications in maintenance. The watchdog releases only a matching backup-owned pause and preserves borrowed operator windows.

## Promotion and restore evidence

Prepare exact source, configuration, storage/key ownership and compatible API/worker release identities. Verify dry-run refusal for mismatched identities and disabled flags. Rehearse a populated restore, deletion-receipt replay, access denial and queue restart in an isolated development database. Record measured backup time, restore time, data integrity and user-visible maintenance duration.

During approved activation, drain write-capable work and establish shared maintenance before changing drivers or flags. Preserve keys, old backups and pending work. Verify both service configurations and release only owned maintenance. A failed or timed-out archive remains incomplete and cannot justify pruning older verified copies.

The current owner authorization covers production server deployment and store publication after their applicable checks pass. Recovery rehearsal, key custody, integrity evidence and measured cutover/rollback duration remain prerequisites for opening the production window. Source-only policy tests use synthetic identities and local fixtures.
