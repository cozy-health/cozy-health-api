# Phase 12.1 — storage security

## Audit

- Railway API APP_KEY is present, base64-encoded, and decodes to 32 bytes.
  Inspection printed only booleans, never the value. APP_DEBUG is false.
- Current tracked `.env.example` has an empty APP_KEY; `.env` is ignored.
  **Historical exception:** commit `685d679` contained `.env` with an app key
  and DB password; `6bdbc6b` removed it. Neither old value matches the current
  Railway production value. "Never committed" cannot be claimed. Treat those
  historical values as compromised anywhere else they may still be used.
- Sanctum consumer/provider bearer credentials are SHA-256 hashed in
  `personal_access_tokens.token`. Raw tokens are returned only when issued.
- Consumer/admin reset codes use `password_reset_tokens.token`; provider codes
  use `provider_password_reset_tokens.token`. Both use `Hash::make` and
  `Hash::check` (default bcrypt, encoded password-hash string), with 15-minute
  expiration. This is distinct from Sanctum's SHA-256 token encoding.
- `User.apple_refresh_token` has an encrypted cast and is excluded from model
  serialization. A round-trip test verifies ciphertext differs from plaintext.
- Provider/admin dashboard session tokens are encrypted with AES-256-GCM in
  HttpOnly cookies. The encryption keys come from environment variables. The
  cookie needs reversible encryption to forward its bearer token to the API;
  the API stores that token's hash. Environment encryption keys are not hashed.
- Backend controller/service logs use generic messages and diagnostic status or
  exception class only. Slow-query logging removes bindings and SQL literals.
  No Laravel Sentry integration is installed. Mobile Sentry disables request
  breadcrumbs, drops every breadcrumb, and reconstructs redacted error events.
- Mobile access tokens already use flutter_secure_storage; the session-only
  option keeps a token in memory. No Hive/SharedPreferences token storage was
  found, so no migration or migration flag was introduced.

## Changes

- Both queued reset mail classes now implement `ShouldBeEncrypted`. Previously
  the verification table held a hash but a queued mail job held the raw code.
  New queued job commands are encrypted using APP_KEY, with worker decryption
  tested. This does not retroactively encrypt existing/failed jobs or backups.
  API and worker must keep the same APP_KEY; changing it requires a data and
  pending-job migration plan, including Apple token ciphertext.
- Mobile HTTP logs retain method/status/error category only. Bodies are always
  redacted; URLs, query strings, headers and exception messages are omitted.
  Repository/UI exception logs omit details and stacks; token-presence and
  individual mood-count logs were removed.
- Native mobile capture protection and reusable SecurityBlur are implemented.
  See mobile `docs/SCREEN_CAPTURE_PROTECTION.md` for coverage and device checks.

## Decisions and verification limits

- Historical credential reuse in other environments requires owner review.
  No rotation or Git history rewrite was performed: that is a separate decision
  and can affect collaborators and encrypted data. Current production values
  do not match the historical app key or database password.
- iOS compilation and actual device capture behavior cannot be verified on
  this Windows machine. Do not treat Dart tests as native-device certification.
- Hive PHI encryption and PDF/volume encryption remain in 12.2, not this change.
- No DEFERRED.md changes, production mutations or pushes in this sub-phase.

## Files changed

Backend: `app/Mail/PasswordResetRequestMail.php`,
`app/Mail/ProviderPasswordResetMail.php`,
`tests/Feature/StorageSecurityTest.php`, this report.

Dashboard: reviewed; no files changed in 12.1.

Mobile: see its screen-protection report for the complete change inventory.

## Tests

Backend: 446 tests, 2,683 assertions; five added storage tests verify consumer
reset hashes, encrypted consumer/provider mail payloads and worker round trips,
Sanctum hashing, and encrypted/hidden Apple refresh tokens.

Dashboard: lint, TypeScript and production build pass; nine existing session,
CSRF and isolation tests pass. Existing test counts are preserved.

Mobile: final analysis, suite and Android compilation results are recorded in
the mobile report. Existing tests remain present.
