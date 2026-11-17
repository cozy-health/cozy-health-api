# Sign-in protection implementation - 30 October 2026

Development only. P02 is in progress, not complete. SIGNIN_SECURITY_ENABLED remains false; it must not be activated before provider-client integration, real owned-inbox acceptance and recovery rehearsal pass.

## Implemented in this batch

- Patient login, registration and Google/Apple entry points can hand off pending challenges without installing provisional credentials or opening private content.
- Added email-code and two-question challenge screens, three-question enrollment, email fallback, visible expiry/cooldowns and bounded rate-limit feedback. No authenticator app.
- Sign-in protection settings require current password and a fresh email code. Identity and question selection use centered branded popups; secret answers/passwords are obscured, kept in memory and cleared on phase/account changes and cancellation.
- Lost save responses require checking the current server setting and explicitly continuing or verifying identity again. They never imply that unanswered changes succeeded.
- Provisional credential cancellation and late verification revocation target only their original credential. New account sessions remain intact.
- Reproduced a server enrollment bypass for both roles: initial email verification permitted private API access and trusted-device reuse before method enrollment. Both are now rejected until configured_at exists. Settings, logout and approved emergency/support exceptions remain available.

## Local evidence

- Server bypass regression failed before repair: both roles returned 200 where 403 was required.
- Sign-in server suite: 18 passed, 164 assertions. Complete server suite: 860 passed, 6,334 assertions.
- New patient controller/widget/service tests: 18 passed, including actual service stale-response rejection, account/email binding, password-plus-email settings, rate-limit cooldown, expiry, cancellation, lost-save recovery and disabled setup at 320px with 200 percent dark-mode text.
- Whole mobile analyzer, full suite, hosted native builds and exact remote commit verification must be recorded in the root delivery report after completion. Earlier artifacts do not verify this batch.

## Still required for P02

Provider browser/BFF pending-challenge, enrollment, settings and cancellation integration; browser response/account isolation; end-to-end patient/provider positive and failure journeys; owned inbox queue/Brevo/delivery evidence; development activation and rollback/recovery rehearsal. Apple identity/device acceptance remains dependent on the final Apple setup. Do not mark the full P02 phase or whole app complete from this batch.

The other approved P00-P11 and S01 tasks remain tracked in COMPLETION_PLAN_2026-10-29.md. Store accounts/products and approved exact USD prices remain last. No production actions are authorized by this document.