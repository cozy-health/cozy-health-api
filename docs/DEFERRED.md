# Deferred work

## Phase 12 owner decisions — 2026-10-09

- Historical `.env` credentials from commit `685d679` are verified dead by the
  owner. Do not rotate them. History cleanup/reuse follow-up is recorded here
  only; no credentials changed and no Git history was rewritten.
- Physical-device capture, proxy/rotation and signed iOS/device checks are
  deferred to Phase 24 (Store Prep). Unsigned iOS compilation for 12.1 passed
  GitHub CI on 2026-10-09; Android CI and Railway deployments also succeeded.

Phase 2's shipped API, schema, parser and deployment fixes are complete. Keep
unused mobile methods in place for future feature wiring; no deletion is planned.

## Scheduled for specific phases

| Item | Work remaining | Scheduled for |
|---|---|---|
| P1: 8 Composer advisories in 3 dependencies | Discovered during Phase 4a JWT install. `composer audit` reports 1 Laravel debug-page XSS advisory, 6 CommonMark XSS/denial-of-service advisories, and 1 Flysystem path-validation advisory. None affect firebase/php-jwt. Evaluate each and update dependencies before Phase 12 | Phase 12 (Security) |
| Dashboard CORS configuration | Railway origins include the provider dashboard, but config/cors.php uses literal origins/methods rather than CORS_ALLOWED_* env vars; both the current config and Railway methods omit PATCH. Wire and verify the dashboard origin and GET, POST, PATCH, PUT, DELETE, OPTIONS | Phase 4.5 (Dashboard endpoints) |
| Phase 2.3 SELECT * removal | Resume after Phase 2.5 ships and is verified on device. Preserve raw key presence before fromJson defaults; retain absent cached fields, clear explicit nulls, and fetch details for incomplete list records. Journal bodies and profile/notification/subscription projections share this prerequisite | After Phase 2.5 (mobile cache merge and detail-fetch fallback) |
| Subscription endpoints | Implement packages, activation, cancellation, receipt verification and restore; retain existing mobile methods | Phase 12 (Payments) |
| Chat message endpoints and relationship audit | Implement conversation message reads/writes and audit relationship loading when the message-list route exists; retain mobile methods | Phase 17 (LLM) |
| Content save/unsave and saved-article HTTP relationship audit | Register the saved-content read route and implement save/unsave, then audit relationship loading; retain mobile methods | Phase 20 (Content Feed) |
| Dashboard, insights and daily affirmations | Build endpoints together with mobile wiring. Use user-local day/week/month boundaries converted to UTC for queries and local-midnight affirmation refresh | Phase 4.5 |
| PD-3 iPhone smoke test | Consent toggles and access log on a real device remain pending. Batch device checks at the end of PD-7 with the full provider flow; an active provider link is needed to test consent. | End of PD-7; after PD-5 or when the first real provider link exists |
| Provider verification code delivery | Backend generates codes but sends no email/SMS. Dashboard auth works, but mobile-to-provider linking needs delivery; mobile says "code sent", which is inaccurate. | Before any real provider uses the link flow |
| Provider dashboard staging | PD-5 provider login is deployed on production Railway; no separate staging dashboard/API flow was verified. Add and record staging URL. | Before external provider onboarding |
| PD-6 legal, consent and retention approval | Draft packet in docs/provider; legal/clinical approval, versioned consent/terms proof and clinical/backup destruction policy remain pending. | Before external provider onboarding |
| PD-6 pilot access and storage | Approve/enforce registration/verification policy; provision durable private report storage; check production APP_ENV=staging label; configure branded domain and test 3+ approved pilots. | Before external provider onboarding |
| Timezone-aware reminder scheduling | Interpret reminder times in users.timezone; no scheduling job exists yet | Phase 18 (Notifications) |
| Crisis detector / persistence audit | Reported test-account journal entries containing "I want to kill myself ," have is_crisis_flagged=false while similar entries are flagged. Investigate detection, save/sync paths and legacy records; punctuation is a hypothesis, not a confirmed cause | Phase 14 (clinician review) or a standalone crisis audit |
| Forgot-password rate limit monitoring | Tuned consumer/admin and provider reset requests to 5/min per IP and 5/15min per email. Track abuse, retries and mail volume before further adjustments. | Ongoing ops |
| Queue worker service | Railway service cozy-health-queue-worker runs `php artisan queue:work --queue=high,default,email --tries=3 --timeout=90`. If it crashes, email jobs accumulate. Monitor queue depth and failed jobs; see docs/QUEUE_WORKER.md. | Ongoing ops |
| Queue worker health check | Connect alerting to the authenticated queue-depth endpoint: warn when depth > 100 or oldest job > 1 hour. Queue depth alone does not establish worker liveness. | Before beta |
| Email delivery smoke test | Weekly: request one reset email for an internal account and confirm inbox delivery. Track SMTP failures and Brevo delivery/bounce events. | Ongoing ops |
| Email deliverability tuning | SMTP processing is verified; inbox/spam placement is not. Verify cozyhealth.org SPF/DKIM/DMARC and Gmail/Outlook/Yahoo/ProtonMail delivery. If test messages land in spam, escalate tuning to P1 for Phase 13 or Phase 24. | Before beta |

## Deferred pending dependencies

| Item | Work remaining | Scheduled for |
|---|---|---|
| Consent audit evidence | Audit log records that consent changed but not the selected data type/new value or consent wording version. Compliance value; not a blocker for MVP or PD-7. | Before public launch or if a legal reviewer requests it |
| PDF cleanup on account deletion | Reports cascade at DB level but files remain in storage until retention pruning. Add immediate file cleanup in deletion flow. | Before public launch |

| Item | Dependency | When |
|---|---|---|
| Mobile cache merge and detail-fetch fallback | Requires a mobile-repo session and a compatible shipped release. Track raw JSON key presence before parsing, preserve absent fields, honor explicit nulls, and mark/fetch incomplete records | Phase 2.5; must precede backend SELECT * removal |
| Quiz covering/index plan optimization | Validate against representative production quiz data and actual query predicates/order. Prior test-data index choices alone do not justify a covering index | Phase 19 (Quiz System), after production data is available |
| Community and content-article relationship audits | CommunityPostController and ContentArticleController do not exist; inspect actual relationships once their endpoints are implemented | With the corresponding feature endpoints |

## Post-MVP / v2.0

No additional work is assigned here at Phase 2 closure. Activity endpoints remain
outside MVP scope; schedule them if the feature is adopted.

## Completed housekeeping

| Item | Resolution | Status |
|---|---|---|
| Composer disable-tls was true | Fixed global Composer config on 2026-10-07: disable-tls=false and secure-http=true | Done |

## Phase 12.2 deployment requirements (owner-approved deferral)

No temporary Railway SSH key is authorized. Historical credentials are confirmed dead; do not rotate or rewrite history.

| Item | Work remaining | Scheduled for |
|---|---|---|
| MySQL CA/TLS verification | Production has no trusted CA configured. Rejects connection without CA. Configure during Phase 24 launch prep. | Phase 24 |
| Next rotation pin | Leaf pin verified (expires Dec 26, 2026). Next-rotation pin not yet available. Add before Phase 24. | Phase 24 |
| Railway AES-256 storage evidence | Private network is WireGuard-encrypted. Volume-level AES-256 evidence not obtained. | Phase 24 |
| Optional git history cleanup post-launch | Historical credentials confirmed dead. Optional cleanup only; no rotation or history rewrite authorized. | Post-launch |

Deferring MySQL verification does not disable the fail-closed connector. Production DB access requires a trusted CA or an explicitly owner-approved temporary deployment opt-out. `/up` alone does not verify database access.
| Item | Work remaining | Scheduled for |
|---|---|---|
| MySQL TLS enforcement | Code supports strict CA verification (12.2). Production runs with DB_TLS_ENFORCE=false temporarily. Configure Railway's CA + set DB_TLS_ENFORCE=true during Phase 24 launch prep. | Phase 24 |

Owner explicitly approved the temporary opt-out on 2026-10-09 for API and worker.

## Phase 12.5 dependency audit

| Item | Work remaining | Scheduled for |
|---|---|---|
| Dashboard lint dependency: braces GHSA-vfj7-8cjw-p6xm | Latest Next 16.4.0 lint tooling still depends on vulnerable braces through micromatch/fast-glob. No patched braces release is available. npm suggests downgrading eslint-config-next to 14.2.35; preserve the supported Next 16 toolchain and revisit upstream fix. Five affected packages represent this one advisory. | Upstream fix / before launch |

## Phase 12.7 consent rollout

| Item | Work remaining | Scheduled for |
|---|---|---|
| Consent enforcement blocking mode | 12.7 implements consent records + version checks with soft (skippable) enforcement. Flip to blocking after Phase 13 when real Privacy Policy + ToS are published. Server-side flag: consent.enforce_blocking. | Phase 13 |
