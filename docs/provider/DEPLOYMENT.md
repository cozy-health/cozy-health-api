# Provider deployment and pilot runbook

2026-10-09. Owners: operations/product. PD-5 is deployed on Railway production;
PD-6 commits are local and unpushed. No new accounts, real-patient reads or pruning
were performed during this work. External onboarding remains gated.

## Current deployment evidence

| Service | develop commit | Railway deployment | Verification |
| --- | --- | --- | --- |
| cozy-health-api | `cbc110131ffb08284b54592b564d1e7f78306ac1` | `79809541-e130-458f-b63c-b57e002723a0` | SUCCESS; `/up` 200 |
| v0-provider-dashboard-component | `c16464a291e5fd6779793e8ab1bf4e87bc87a84e` | `4f619ea2-2ba4-47bb-a744-b8e7ce5a6e0a` | SUCCESS; `/login` 200; protected root 307; unauthenticated bridge 401; CSRF 403; validation 422 |

Backend: `https://cozy-health-api-production.up.railway.app`.
Dashboard: `https://v0-provider-dashboard-component-production.up.railway.app`.
Branded `providers.cozyhealth.org` and separate staging are not configured by this
phase. The 422 check used an empty login body and created no provider credential.

## Environment and infrastructure

- Backend: HTTPS, `APP_ENV=production`, `APP_DEBUG=false`, stable application key,
  protected database, correctly trusted TLS proxy configuration, shared cache and
  mail/reset delivery. Confirm runtime values privately rather than exposing env
  output. The deployed backend handles bearer credentials, not dashboard cookies.
- Dashboard server: `PROVIDER_API_URL` ending `/api/v1/provider/`, exact HTTPS
  `PROVIDER_DASHBOARD_ORIGIN`, and random 32-byte hex `PROVIDER_SESSION_SECRET`.
  These were configured for the Railway production URL. Never publish them through
  `NEXT_PUBLIC_` or logs. Share the secret across replicas; rotation signs users out.
- Private report storage: generation uses Laravel `Storage::disk('local')`.
  Railway metadata currently shows no API volume for report files. Provision
  private durable storage at the actual Laravel local disk root before real PDFs
  are relied on; verify persistence across restart/redeploy, permissions, backup
  and encrypted storage. Do not expose that directory as public storage.
- Queue worker and scheduler: current web container supervises PHP/nginx, not a
  scheduler/queue worker. Provision/test required services. PD-6 installs no
  destructive prune schedule. Follow RETENTION.md to preview and authorize pruning.
- Domain: attach `providers.cozyhealth.org` to the dashboard service, configure the
  indicated DNS record, verify certificate/HTTPS and update the exact origin env.
  Deploy and recheck CSRF. Do not assume the domain is live until DNS/TLS pass.

## Release procedure

1. Create isolated staging services/database and report storage. Keep staging
   secrets separate; use synthetic data and distinct origins. Record the staging
   URL in DEFERRED.md. Verify the full workflow there before external onboarding.
2. Backend `php artisan test`; dashboard `npm test`, `npm run test:integration`,
   `npm run lint`, `npx tsc --noEmit`, `npm run build`. Configure Playwright for the
   browser pass; a skipped browser step is not evidence of a fresh pass.
3. Review migrations/backups, env values and exact release SHAs. Push authorized
   backend develop first and verify Railway deployment/health. Then dashboard
   develop; wait for exact SHA success. PD-6 introduces no schema migration.
4. Smoke protected root, login, wrong-origin rejection and protected API. On staging
   additionally test real auth/logout, reset-mail delivery, expiry at 30 minutes,
   client reads, note persistence, assignments, PDF persistence/download, consent
   withdrawal, signed URL denial and cross-provider isolation. Verify backend audit
   and patient access log for each sensitive request.
5. Record deploy IDs, UTC time, release SHAs, test evidence and operator. Recheck
   secret-redaction, error monitoring and rollback readiness.

## Pilot policy and gates

Recommended policy for product approval: invite-only pilot, manually verified
individual providers with recorded practice/license/jurisdiction and agreements.
The API's `/auth/register` currently remains public, and account active status is
distinct from verification. The dashboard omits signup, which does not close the
API. Enforce the approved registration/verification policy in code before claiming
invite-only operation. Do not enable open signup by omission.

Before onboarding: signed legal/clinical approvals, applicable vendor/practice
agreements, versioned terms/consent proof, approved retention/backup/hold policy,
staging full flow, durable PDFs, provider verification-code delivery, approved
registration/access rules and tested breach escalation. Then onboard at least
three approved pilot providers and verify access end-to-end with authorized
patients. Device checks remain scheduled for end of PD-7; record them as pending.

## Rollback and incident handling

Redeploy a known-good SHA through Railway, keeping env compatibility; do not run
database down migrations blindly. The pre-PD-5 dashboard is a prototype, so it is
not a safe authenticated-portal rollback target. For a bridge incident, suspend
provider access/revoke tokens or temporarily gate sign-in, rotate the bridge
secret, preserve audit evidence and follow SECURITY_CHECKLIST.md. Rotation alone
does not revoke backend bearer tokens. Test rollback on staging and verify private
PDFs persist; no production rollback was executed here.
