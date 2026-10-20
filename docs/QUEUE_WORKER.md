# Queue worker

Use `QUEUE_CONNECTION=database` for the API and a second Railway service built from the same repository. Share the database, cache, encryption key and mail settings. Start the worker with:

```sh
php artisan queue:work --queue=high,default,email --tries=3 --timeout=90
```

The database connection's `retry_after` must exceed the worker timeout (use 120 seconds). `high` takes priority, followed by general work and email. Add notifications, moderation and content queues when those features dispatch jobs.

Run migrations from the deployment service, not concurrently from workers. Restart workers on deployment (`php artisan queue:restart`). Monitor failed jobs with `queue:failed`, retry with `queue:retry`, and monitor `/up` for queue backlog. `/up` checks storage/backlog, not worker liveness; supervise the worker service in Railway.

Authenticated administrators can POST `/api/v1/admin/queue/test-email` with `{"email":"your-test-address"}` to dispatch `SendTestEmail`. The endpoint is throttled and unavailable to ordinary users.

## Railway production service

`cozy-health-queue-worker` is connected to `cozy-health/cozy-health-api`, branch
`develop`, root `/`, and uses the repository Dockerfile. Its start command is the
command above, with one replica, restart on failure (up to 10 retries), and no
HTTP healthcheck or public domain. The worker start command bypasses the web
entrypoint so it does not start Nginx or run migrations/seeds. Run migrations
from the API deployment before workers need new schema.

Worker application, database, mail and cache variables reference the API service
with Railway references such as `${{cozy-health-api.APP_KEY}}`; they are not
duplicated secrets. Worker overrides are `APP_ENV=production`, `APP_DEBUG=false`,
`QUEUE_CONNECTION=database`, and `DB_QUEUE_RETRY_AFTER=120`. API and worker must
use the same database, encryption key and mail transport. Keep these references
in place when rotating API credentials.

Railway service settings define the worker start/restart configuration. Confirm
each deployment's effective start command and `healthcheckPath=null`; do not
inherit the API's `/up` healthcheck for a worker. New Railway services reject a
custom legacy `railwayConfigFile`; use service settings or Railway IaC to manage
worker settings. The existing API's legacy `railway.toml` remains unchanged.

## Queue monitoring

After deploying the backend monitoring change, operations admins can GET
`/admin/health/queue-depth` or `/api/v1/admin/health/queue-depth` with an admin
operations bearer token. Patient/provider credentials and the dashboard's
aggregate-only analytics credential are rejected. Responses have no-store
headers, return no job payloads or identities, and are throttled at 30/min:

```json
{"depth":0,"oldest_job_age_seconds":0,"warning":false}
```

Depth includes all rows in the configured database queue table, including reserved
jobs. Age is measured from the oldest job's creation time; an empty queue returns
zero. `warning` is true when depth > 100 or oldest age > 3600 seconds. This
endpoint reports warning state; external alert delivery must still be configured
before beta. Monitor `failed_jobs` separately: a drained queue can contain failed
deliveries, and an empty queue cannot prove an idle worker is alive.

Check worker deployment logs for `RUNNING` followed by `DONE`, then query:

```sql
SELECT COUNT(*) AS depth FROM jobs;
SELECT COUNT(*) AS failed FROM failed_jobs;
```

After the worker is healthy, request one new reset email and verify the job is
processed and the email reaches the inbox. Old queued reset codes may have
expired or been superseded; request a fresh code after draining the backlog.
SMTP acceptance and a completed job do not prove inbox delivery. Check Brevo
delivery/bounce events and ask the mailbox owner to confirm receipt. Do not put
codes, passwords, job payloads or mail credentials in logs or reports.

Forgot-password now has a 5/min per-IP limiter and a 5/15min per-email limiter
for consumer/admin and provider accounts. Follow the backend's `Retry-After`
header; the admin reset page displays the remaining wait rounded up to minutes.
Versioned email limiter keys avoid carrying the previous one-hour block into the
new policy. Keep both protections enabled.

## Verification on 2026-10-09

The GitHub deployment at backend commit `11fa747` started successfully. Four
previously untouched `email` jobs were processed with `DONE` worker logs; the
database then reported `jobs=0` and `failed_jobs=0`. A subsequent HQ reset request
returned 429 with `Retry-After: 2086` because its per-email limit was exhausted.
A fresh Cordelia reset request returned 200 while the API was temporarily in sync
mode. After restoring the API to `QUEUE_CONNECTION=database` and setting
`DB_QUEUE_RETRY_AFTER=120`, the API redeployment succeeded. One fresh
`SendTestEmail` job to HQ was enqueued and processed by the worker with `DONE`;
the queue again reported `jobs=0` and `failed_jobs=0`. Both API and worker had
one running replica, and API `/up` returned 200.
Inbox receipt and completion of the password reset require mailbox-owner
verification. No reset codes or passwords were accessed or recorded.

The owner subsequently supplied a Gmail screenshot showing the queue-check
message labeled Inbox. This verifies inbox placement for that message only;
the screenshot does not establish which account is open or delivery of both
accounts' reset emails.
