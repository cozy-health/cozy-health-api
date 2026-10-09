# Phase 12 security readiness — 2026-10-09

Status: initial repository review completed; security implementation stopped at
uncovered decisions, as requested by the owner. This is not a security sign-off
or a claim that Phase 12 is implemented. The current request covers storage,
crypto, auth, network headers, code security, authorization and consent
versioning. Community/image moderation work in the longer build plan belongs
with those future feature phases unless separately authorized.

## Deployment and email baseline

- Backend monitoring commit `361c4b7` is pushed. API and queue worker deployments
  succeeded, and production `/up` returned 200 after deployment.
- Worker source is `cozy-health/cozy-health-api`, branch `develop`, with database
  queues and shared variable references. Four pending resets and a fresh queue
  check completed without failed jobs during the previous verification.
- Gmail connection discovery found the connector uninstalled/unconnected. Neither
  HQ nor Cordelia's inbox, spam or promotions folder has been inspected. A
  connector suggestion was made; access must be confirmed before mailbox reads.
- Spam placement is unknown. The deliverability item is recorded in DEFERRED.md,
  with conditional P1 escalation if spam placement is confirmed.

## Confirmed source findings

| Area | Evidence | Work needed |
|---|---|---|
| Mobile token storage | `lib/core/storage/token_storage.dart` uses flutter_secure_storage; nonpersistent sessions stay in memory. | Extend secure storage to approved refresh/device-session design and test upgrades/logout. |
| Local PHI storage | `lib/core/services/local_db_service.dart` opens mood, journal and other Hive boxes without encryptionCipher; `lib/core/repositories/endpoint_cache.dart` also opens an unencrypted cache. | Design and test lossless encryption migration covering PHI, queued writes, legacy boxes and derived caches. |
| Request logging | `lib/core/api/api_interceptors.dart` logs request URIs and non-redacted bodies in debug mode; other repositories log exception strings. | Scrub bodies, query values and sensitive exception detail, including debug builds. |
| Consumer sessions | Backend `AuthController` issues Sanctum tokens without per-token expiry; `config/sanctum.php` sets global expiration to null. | Approved short access/long refresh design, device-session management and migration. |
| Password-reset invalidation | Consumer `PasswordResetController` changes the password without deleting existing Sanctum tokens. Provider reset and private admin-password setup do revoke tokens. | Make consumer reset revoke affected sessions and approved refresh credentials atomically. |
| CORS | `config/cors.php` hardcodes origins and omits PATCH. | Reconcile deployment origins and required methods; verify cookie vs bearer CSRF boundaries. |
| Native protections | Android main manifest does not explicitly set cleartext/backup restrictions; capture/app-switcher protection and pinning need a dedicated native review. | Verify effective release manifests and native behavior on supported devices. |
| Consent | Build plan specifies document types and versioned acceptance metadata, but approved document versions were not supplied. | Owner-approved version manifest, acceptance/reacceptance rules and mobile/backend integration. |

These are source observations, not exploit findings. Dependency, secret, TLS,
RBAC and native-device audits remain to be performed. No production auth or
mobile security behavior was changed during this review.

## Decisions requiring owner direction

1. **Refresh/session design.** Recommended proposal: keep Sanctum opaque access
   tokens, add hashed rotating opaque refresh tokens per device, detect reuse and
   revoke the affected device's token family. Proposed access lifetime: 15 minutes.
   The plan already gives a 30-day inactivity timeout and 5 minutes for sensitive
   screens; refresh absolute lifetime and legacy-token migration grace period are
   still unspecified. Agree those before schema/API/mobile implementation.
2. **Pinning and rotation.** Supply/approve the production API hostname, pin owner,
   current and backup public-key pins, and rotation process. Do not enable a
   fail-closed pin set based on a single observed Railway certificate. Pin rollout
   must include an approved outage/recovery procedure and proxy/device tests.
3. **Lockout and password policy.** The plan says N failed attempts but gives no N,
   observation window, lockout duration or recovery policy. Approve these and the
   password policy before applying behavior that can deny account access.
4. **Encryption/key recovery.** Approve key lifecycle and behavior when a device's
   secure-storage key is missing, especially with unsynced offline records. A
   migration must not silently delete unreadable or unsynced PHI.
5. **Consent versions.** Supply approved ToS, privacy, AI disclosure, health-data,
   marketing and relevant UGC document versions/content. Do not invent a legal
   acceptance version or treat existing provider toggles as acceptance of all
   documents.

The opaque-token recommendation fits the existing Sanctum implementation; it is
an implementation proposal, not an owner-approved policy. Sanctum supports
per-token expiry and revocation; it does not supply this application refresh
flow. See [Laravel 12 Sanctum](https://laravel.com/framework/docs/12.x/sanctum).
Replay detection through refresh rotation follows the approach described for
public clients in [RFC 9700 section 4.14](https://www.rfc-editor.org/rfc/rfc9700.html#section-4.14);
this does not make the proposed Sanctum flow an OAuth implementation.

Once decisions are resolved, implement each approved area with targeted tests,
then run backend and mobile regression checks. Physical-device capture/biometric
tests and certificate rotation/proxy tests remain explicit ship gates.
