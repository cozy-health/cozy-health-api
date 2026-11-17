# Disabled age/guardian staging release

Target: Railway project `celebrated-flexibility`, environment `development`.
Use `api-dev.cozyhealth.org`, `dev.cozyhealth.org` and
`dashboard-dev.cozyhealth.org`. Railway's older generated service names include
`production` even on development services; use the explicit environment and
custom domains, never infer the target from a generated hostname.

## Deploy without activation

1. Record existing successful deployment IDs for API, worker, website and
   dashboard. Confirm development and production have distinct database
   credentials and application encryption keys. Private MySQL hostnames and
   database names can match across isolated Railway environments.
2. Apply `deploy/age-staging.env.example` to development API and worker only.
   Preserve their existing unique credentials. Log mail prevents external
   delivery; access to logs must remain restricted. Do not exercise real inbox
   delivery until an isolated mail sink and synthetic recipients are configured.
3. Set development website `GUARDIAN_SITE_ORIGIN=https://dev.cozyhealth.org` and
   `GUARDIAN_API_URL=https://api-dev.cozyhealth.org/api/v1/guardian/`.
   Keep dashboard relays on the development API and dashboard origins.
4. Run release tests, then upload the reviewed API to development. The existing
   API pre-deploy command is `php artisan migrate --force --isolated`; the
   worker must not run migrations. Website and dashboard builds may proceed
   independently; wait for API health before releasing the matching worker or
   treating any new client workflow as verified.
   For a direct worker upload, omit the API's `railway.toml` from the worker
   snapshot so the existing service-level queue command and absent HTTP health
   check are preserved. Verify those settings in the resulting deployment.
5. In the deployed API run `php artisan guardians:release-check --schema`.
   It reads flags and schema only, emits no private records and exits nonzero
   for enabled flags/countries or a missing migration. It is a deployment check,
   not an approval to activate. Confirm `/up` and `/api/v1/config`, the public
   guardian page and the dashboard sign-in all respond successfully. Confirm
   public configuration reports `age.enforced=false`.
   `powershell -File deploy/verify-age-staging.ps1` repeats these public checks
   against fixed development origins and returns a failing exit code until the
   API age flag and guardian page security headers are present.

## Rollback

Redeploy the recorded previous successful application deployments using
Railway's deployment rollback. Retain the disabled staging variables. Keep the
additive guardian tables and user columns; do not run `migrate:rollback`,
`migrate:fresh`, `db:wipe`, or prune execution on this environment. Do not reset
or copy a production database. Check health and configuration again after any
rollback. A tested rollback requires an actual controlled staging exercise;
recording these instructions alone is not evidence that it passed.

After eventual activation, stop minor access with `AGE_HEALTH_DATA_READY=false`
and stop new invitations with `GUARDIAN_INVITATIONS_ENABLED=false`. Disabling
enforcement would reopen access and is not an activation rollback strategy.

## Acceptance still required before activation

Signed-device/old-client behavior, isolated inbox delivery and withdrawal,
MySQL concurrency, controlled rollback, approved countries and assurance,
effective legal documents, retention and backup expiry, and named trained
reviewers/appeals remain separate acceptance checks. Do not enable flags or
invent approval references to make them pass. The shared BUILD_PLAN records
what was actually verified and the exact deployment IDs.
