# Operations analytics onboarding (PD-7)

Owner: Cordelia, Cozy Health operations role. Read-only aggregate analytics;
no patient records, names, emails, identifiers, content or individual drill-down.
PD-7 is committed locally and must be pushed/verified by the owner. PD-6 backend
`8132684` was pushed and Railway deployed successfully; `/up` returned 200.

## Release and configuration

1. Push backend develop after reviewing the PD-7 report; wait for exact-SHA
   Railway SUCCESS and `/up` 200. The new migration creates
   `admin_metric_releases`, containing suppressed aggregate JSON only. Verify it
   applies before starting dashboard authentication. Existing role schema is reused.
2. Configure dashboard server `ADMIN_SESSION_SECRET` with a new random 32-byte hex
   secret distinct from `PROVIDER_SESSION_SECRET`. Generate it locally in a secure
   terminal/password manager and store it directly in Railway; never paste it into
   a report, public env variable or commit. It must be shared across replicas.
3. `ADMIN_API_URL` optionally overrides the server API URL; it must end in
   `/api/v1/admin/`. Otherwise it uses the admin sibling of `PROVIDER_API_URL`.
   `ADMIN_DASHBOARD_ORIGIN` optionally overrides the exact portal origin; otherwise
   it uses `PROVIDER_DASHBOARD_ORIGIN`. Production requires HTTPS. No wildcards.
4. Push dashboard develop and wait for its exact SHA deployment. Verify protected
   `/admin`, `/admin/usage`, `/admin/retention`, `/admin/safety` and `/admin/login`.
   The existing Railway domain is usable. Branded `providers.cozyhealth.org` remains
   an owner infrastructure workstream; update the origin and verify DNS/TLS when
   attaching it. PD-7 is not pushed by the implementation agent.

## Cordelia's account

Provision only the owner's confirmed email. Never guess from a name or grant a
role to a different account. This phase adds:

```sh
# Existing active consumer account (password preserved):
php artisan user:make-admin '<confirmed-owner-email>'

# New account; generated inaccessible bootstrap password is never printed:
php artisan user:make-admin '<confirmed-owner-email>' --create --name='Cordelia'

# Generate a strong password in a password manager, then enter it privately twice:
php artisan user:admin-password '<confirmed-owner-email>'
```

Run commands inside the deployed backend service, not against a developer database.
The first command refuses missing, disabled, demo or deleted accounts. `--create`
requires a name, does not replace an existing password, and does not restore deleted
accounts. Provisioning a new account sends no email automatically. Password setup
uses hidden terminal prompts and revokes existing tokens. Generate/send the
password out-of-band using an approved secure channel; do not print it in the
PD-7 report, command arguments, logs or tickets. Prefer the owner's password
manager. Login uses `/admin/login` with that email and password.

An existing password-reset flow is available through the consumer API/app, but
successful mail delivery and queue processing must be verified before relying on
it. Admin login issues a separate 30-minute absolute credential; provider/patient
tokens and browser sessions are rejected. No public admin signup exists.

If provisioning before PD-7 deployment, the existing `user:make-admin` command can
promote an existing active account only. New-account creation/password setup require
the updated commands or an approved controlled bootstrap using the existing command.
The PD-7 report records whether the actual production account was provisioned;
providing commands alone is not a claim that it was.

## Access and troubleshooting

Open `/admin/login` and sign in. The overview shows recorded daily activity,
weekly signups, retention summary and current service health. Usage offers
7d/30d/90d; retention shows mature D1/D7/D30 signup cohorts; safety shows counts of
stored crisis flags only. Use Sign out when finished. After 30 minutes re-login
is required even during active use. Settings/provider APIs cannot be reached with
this analytics credential. Every API request rechecks role and account activity.

- `<5`: privacy suppression; no underlying exact count is returned. It can also
  hide larger cells to protect small related/complementary groups. It is not zero.
- “Not mature”: the required observation window is incomplete; no denominator or
  rate is published yet.
- “Unavailable”: the instrumentation needed to measure the metric is absent.
- Error: retry; no exception messages, logs or credentials are displayed.
- No buckets: an empty reporting array; the UI does not invent numbers.

See METRICS.md for the exact definitions and limitations. This analytics area is
not a clinical safety-monitoring queue and does not provide individual follow-up.

## Verification and support

Backend: `php artisan test`. Dashboard: `npm run lint`, `npx tsc --noEmit`,
`npm run build`, `npm test`, `npm run test:admin`, `npm run test:integration`.
Set `ADMIN_PLAYWRIGHT_PATH` / `PROVIDER_PLAYWRIGHT_PATH` to an installed Playwright
module to run browser suites. Fixtures are isolated and do not contact production.

After deployment use the confirmed owner account to verify actual login/logout,
aggregate pages, non-admin denial, expiry and no patient-data access. Record release
SHAs and results. Before external providers launch, the owner continues legal,
clinical, staging, code delivery, storage and pilot workstreams; those do not block
PD-7 development. Consent evidence and immediate PDF deletion cleanup are deferred
to public launch/legal review as requested, not MVP or PD-7 blockers.
