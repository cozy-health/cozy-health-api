# Development deletion safeguards

The owner approved seven-day backup retention and fourteen-day encrypted deletion
records. Additional full recovery drills are deferred. Code delivery does not
activate additional storage usage: `DELETION_LEDGER_ENABLED` defaults to false
until the cost estimate is approved. No public API or production policy changes.

## Activation and custody

Use only the approved Railway development project/environment and API hostname.
When enabled, API and worker must use private S3-backed `local` storage, not the
database volume. Receipts occupy `private/deletion-ledger/v1` within that existing
disk. Existing application encryption authenticates and encrypts each receipt;
retain its APP_KEY securely outside database backups. Do not rotate that key as
part of activation. Loss of both the key and its secure recovery copy makes the
receipts and existing encrypted application data unrecoverable.

Verify private access and the same key/disk configuration on API and worker before
enabling the flag. Record the activation timestamp. Deletions before activation
are excluded by the required `DELETION_LEDGER_ACTIVATED_AT` timestamp. Set it to
the actual UTC activation time on both services, not a historical date. Snapshots
before that timestamp are refused by replay. Deletions before activation
have no ledger evidence; never claim that an older snapshot can be reopened safely
without separate reconciliation. The code refuses activation outside testing or
the explicitly identified development environment.

## Deletion behaviour

An authorized deletion locks and rechecks its account, saves an encrypted pending
receipt, then runs the existing account/session/private-file cleanup transaction.
Storage failure stops deletion. Database failure records an aborted outcome when
possible. Only a committed deletion plus a completed receipt permits the existing
success response and confirmation mail. A crash or failed outcome write leaves
the receipt pending and blocks restore acceptance. Database and object storage
are not a distributed transaction; that uncertainty is represented explicitly.

Receipts contain a random operation ID, original account ID and creation timestamp,
request/expiry times, state and private-file references. They contain no names,
emails, passwords or health content. Account creation time is used instead of the
mutable email/password privacy-request fingerprint. Private object references are
encrypted and restricted to known private disks and safe relative object keys.

On the original authoritative database only, inspect interrupted work and preview:

```sh
php artisan privacy:deletions-reconcile OPERATION_UUID completed --original-database
php artisan privacy:deletions-reconcile OPERATION_UUID completed --original-database --execute
```

Use `completed` only when the account is absent from that original database;
`aborted` requires the original matching account to exist. Never infer an outcome
from an old restored snapshot. Stop/reconcile any in-flight deletion first. Dry
run is the default. Do not paste decrypted records, keys or private paths into logs.

## Before reopening a restore

Keep the restored development instance isolated and in maintenance mode. Stop its
external queue workers, mail and provider jobs. Confirm the original backup
timestamp is within seven days and after ledger activation; retain the independent
object store/key rather than restoring them backwards with the database.

```sh
php artisan privacy:deletions-replay --snapshot=2026-11-09T00:00:00Z
php artisan privacy:deletions-replay --snapshot=2026-11-09T00:00:00Z --execute
```

Substitute the actual snapshot timestamp. The command validates every receipt and
all target identities before applying changes. Pending, corrupted or mismatched
records block acceptance. Execution requires maintenance mode; stopping workers
and ensuring isolation are operator prerequisites, not facts the command can
independently prove. Replay applies completed requests newer than the snapshot,
revokes restored access and queues private-file cleanup without Apple calls or
confirmation emails. It is safe to repeat. Run the existing `avatars:cleanup`,
verify relevant objects are gone and access is revoked, then reopen deliberately.

The application does not automatically detect or reopen a restored database.
An empty ledger is not proof that historical deletions were reconciled.

## Retention and testing

`privacy:deletions-prune` previews expired resolved receipts; `--execute` removes
them after fourteen days. The existing scheduler runs that command daily only
when the feature is explicitly enabled. Pending receipts remain for review, with
a warning when overdue; they are never silently pruned. This can increase storage
until the operator resolves them.

Regression tests use fake private storage and synthetic restored rows. They cover
authorization, storage/commit failures, rollback, identity mismatch, corruption,
repeated replay, object cleanup, reconciliation and retention. They do not replace
the deferred full infrastructure recovery drill or certify a recovery SLA.
