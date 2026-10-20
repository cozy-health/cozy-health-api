# Phase 12.2 — crypto implementation and audit

Status: local implementation; **not ready to deploy** until the infrastructure
and certificate prerequisites below are satisfied. 12.1 is pushed; 12.2 is not.
No production credentials were rotated, no history rewritten, and no 12.3 work
started.

## 12.1 push verification

Backend `2a9e5a1`: Railway API and queue worker both succeeded for this exact SHA.
Production HTTPS `/up` returned 200. Mobile `bb7319a`: Android
[CI 37909115004](https://github.com/cozy-health/cozy-health-mobile-app-main/actions/runs/37909115004)
and unsigned iOS
[CI 37909114930](https://github.com/cozy-health/cozy-health-mobile-app-main/actions/runs/37909114930)
succeeded. Unsigned iOS compilation is verified; physical/signed-device checks
are recorded in DEFERRED.md for Phase 24.

## Storage encryption

- Application encryption is AES-256-CBC, with a 32-byte APP_KEY, verified by a
  regression assertion and the 12.1 environment inspection. Laravel's encrypted
  payloads include a MAC. APP_KEY must remain available for reports, Apple refresh
  tokens and encrypted queue payloads. No key rotation was performed.
- New provider PDF bytes are encrypted before private local storage. Reads
  authenticate/decrypt inside the existing consent/link lock. Plaintext and
  tampered ciphertext are rejected; no legacy plaintext read fallback exists.
- `php artisan reports:encrypt` migrates existing local PDFs through a temporary
  encrypted copy, read-back verification and local filesystem rename. The
  original remains intact on verification failure. The command is idempotent and
  reports counts without paths, PHI or exception details. It has not run in
  production. Coordinate a maintenance window and verified backup/restore plan
  before deployment; legacy PDF reads fail until migration is complete. Existing
  durable storage/retention work remains separately tracked.
- Mobile Hive uses AES-256-CBC/PKCS7 (`HiveAesCipher`) with random 32-byte per-box
  keys in Flutter secure storage. All application boxes, including queues,
  caches, device registry, drafts and inactive native-account scopes, are routed
  through encrypted storage. Full adapter records are copied, flushed, reopened
  and compared before a durable migration marker permits legacy cleanup.
- Missing/changed keys, corrupt data and unverified legacy changes preserve data
  and block access. Startup shows a generic recovery message. Hive automatic
  truncation recovery is disabled. Migration metadata protects against erasing
  new plaintext writes made by a downgraded app. Hive 2.2.3 is pinned because
  full-record migration uses its binary adapter implementation. This is storage
  confidentiality, not a claim of authenticated Hive encryption or filesystem
  secure erasure. Native upgrade/device validation remains in Phase 24.

## MySQL and transport findings

- Railway production MySQL is a hosted MySQL service with a volume at
  `/var/lib/mysql`. API/worker have no PDF volume. SQL rows are not individually
  encrypted by this change. Railway staff state that user data is encrypted at
  the underlying disk layer; a Railway support bot states AES-256 includes
  volumes/backups. The authenticated Trust Center encryption policy was not
  retrieved. **AES-256 for this project's volume/backups is not independently
  verified.** Retain the applicable control/attestation before sign-off.
- Production DB_HOST is private (`*.railway.internal`). Railway documents
  WireGuard-encrypted private traffic. This does not prove a MySQL TLS session.
  No MYSQL_SSL_CA is currently configured; runtime Ssl_cipher/Ssl_version were
  not inspected. A new SSH key was not registered after approval review rejected
  creating persistent remote access for the audit.
- New MySQL configuration requires TLS outside local/testing, verifies the
  configured CA and server certificate, and rejects missing/unreadable trust
  before opening a connection. It additionally checks Ssl_cipher after connection.
  **Provision/mount a real trusted CA, verify the TLS-capable endpoint and both
  API/worker sessions before pushing this backend commit.** Current production
  would fail DB access with the new default because the CA is missing. Do not
  treat WireGuard or DB_REQUIRE_TLS=false as completing the TLS requirement.
- Backend rejects non-HTTPS application requests outside local/testing, using
  Railway's forwarded protocol but not its forwarded host. Internal `/up` probes
  remain available over HTTP. Public HTTP `/up` currently redirects to HTTPS (301).
  SMTP now requires TLS rather than opportunistic STARTTLS. New provider resource
  URLs require HTTPS.
- Android explicitly disallows cleartext, uses system trust anchors and disables
  app backup. iOS ATS arbitrary-load/media/web exceptions are explicitly false.
  Native mobile API requests only permit the HTTPS API destination and reject
  redirects/proxies. Dashboard already requires HTTPS API URLs in production,
  rejects redirects, and uses AES-256-GCM encrypted sessions; no changes needed.

## Pinning and rollout gates

Mobile pins the leaf DER SHA-256 before transmitting HTTP headers/body, while
retaining normal chain/hostname/expiry verification. Current and next pins are
mandatory public build defines. Defaults are empty and API requests fail closed.
Synthetic loopback tests prove current/next acceptance and pre-request mismatch
rejection. They do not supply production rotation certificates.

The live current Railway leaf, observed over verified TLS 1.3 on 2026-10-09, is
`fb370ca26842da2b2ff8e3182e0e8cfc6fb8eac32cdb5718f45a4b39b9e140af`,
expiring 2026-12-26. The next leaf and named rotation owner are missing. Railway
controls wildcard renewal. Resolve advance certificate availability or the TLS
hosting/domain arrangement before rollout. See both repositories' CERT_ROTATION.md.

Existing mobile CI was not changed to require unprovisioned pin variables after
automatic approval review rejected that persistent build gate. Builds without
the two defines block health API access. Do not distribute default artifacts or
push 12.2 until real pins and build wiring are ready.

## Secret audit

Gitleaks 8.30.1 official Windows release was downloaded and its published SHA-256
checksum verified. Scanned tracked/unignored source snapshots (including new
files) and all local Git refs in backend, mobile and dashboard with full redaction.
Ignored runtime .env files were not copied into reports. No tests were suppressed.

| Scope | Results |
|---|---|
| Backend current | No Gitleaks findings |
| Backend history | One finding in historical .env commit 685d679; earlier manual audit also identified its DB password |
| Dashboard current/history | No findings |
| Mobile current | Two synthetic localhost TLS fixture keys and one dynamically generated secure-key test expression; reviewed non-production findings |
| Mobile history before this commit | No findings |

Manual credential-source review also found the existing intentionally public
synthetic demo-account password and test/sample values. The demo seeder refuses
to replace a non-demo account. Do not equate public demo/test identities with a
production secret. Scanner results do not prove the absence of every secret.
The owner confirmed historical .env credentials are dead and directed **no
rotation**, recorded only in DEFERRED.md. No real values are included here.

## Verification

- Backend: **454 tests, 2,707 assertions**, including eight new crypto tests;
  existing provider report authorization/download tests remain intact.
- Mobile: final count/analysis/Android compile are recorded in its crypto report.
  Existing 263 tests remain, with migration and real TLS regression additions.
- Dashboard: **9 tests**, lint, TypeScript and production build passed; unchanged.
- Production tests are limited to non-mutating deployment/health/TLS inspection.
  No 12.2 runtime migration, database TLS change or certificate rotation ran.

## Files

Backend: .env.example; app/Database/TlsMySqlConnector.php;
app/Providers/AppServiceProvider.php; config/database.php; config/security.php;
bootstrap/app.php; app/Http/Middleware/RequireHttps.php; config/mail.php;
app/Services/Provider/EncryptedReportStorage.php; ProviderReportService.php;
app/Http/Controllers/Api/V1/Provider/ProviderReportController.php;
ProviderResourceController.php; app/Console/Commands/EncryptProviderReports.php;
tests/Feature/CryptoSecurityTest.php; tests/Feature/Provider/ProviderReportTest.php;
docs/DEFERRED.md; docs/CERT_ROTATION.md; this report. Mobile inventory is in its
report. The shared docs/DEFERRED.md was updated outside the Git repositories.

References: [Railway staff storage explanation](https://station.railway.com/questions/are-databases-encrypted-at-rest-0e719d6c),
[Railway support AES-256 statement](https://station.railway.com/questions/data-protection-questions-encryption-a-e5128546),
[Trust Center](https://trust.railway.com/),
[private networking](https://docs.railway.com/networking/private-networking),
[Hive cipher](https://pub.dev/documentation/hive/latest/hive/HiveAesCipher-class.html),
[Android cleartext guidance](https://developer.android.com/privacy-and-security/risks/cleartext-communications),
[Apple ATS](https://developer.apple.com/documentation/Security/preventing-insecure-network-connections).


## Superseding owner rollout decision — 2026-10-09

Source deployment is authorized with DB_TLS_ENFORCE=false explicitly configured on API and worker. This restores pre-12.2 DB connection behavior; strict CA verification remains the default and is required during Phase 24. Earlier pre-push CA gates above are superseded by this temporary opt-out. MySQL TLS-only configuration is unverified. No SSH key, production PDF migration, rotation or history rewrite is authorized. See CRYPTO.md.
