# Nigerian Student verification and in-app purchase readiness

Approved owner policy, implemented on development branches, 5 November 2026. Supersedes the earlier exact student-only-domain/document launch policy. This document describes local source until a deployment is recorded.

## Qualification and user flow

Users retain their normal Cozy login. Institutional domains beneath `.edu.ng` qualify automatically; other Nigerian tertiary email domains require explicit administrator approval. Exceptions match exactly; explicit blocks cover the domain and its subdomains. Personal email providers and `edu.ng` itself cannot be approved. Mailbox ownership is accepted, including working staff/alumni accounts; it is not proof of current enrollment or the user's physical location.

`POST /api/v1/student-verification/email` sends a hashed, account-bound six-digit code through the existing configured mail transport/Brevo integration. Lifetime is ten minutes, with three incorrect attempts. Existing authenticated token/link confirmation remains compatible. Sending has account/address minute cooldowns, account/address hourly limits of five, and an IP hourly limit of twenty. Confirmation has ten requests per minute per account. A replacement invalidates the old challenge. Unique protected email claims and transactions prevent one school email qualifying multiple accounts.

Confirmation grants twelve-month **eligibility**, never a paid entitlement. Renewal opens thirty days before expiry. Early renewal extends the existing expiry; late renewal starts at successful verification. Existing 30-, 7- and 1-day reminders are preserved, with an additional expiry reminder. Daily `students:prune-verification` expires eligibility, purges unusable challenges and applies retention. Request-time eligibility checks remain authoritative.

Documents are disabled by default (`STUDENT_DOCUMENTS_ENABLED=false`), including for old clients. Historical administrative decisions/evidence cleanup remain supported. No unverified institution dataset or exception domains are seeded. The existing domain registry holds Nigerian institution name/type/country; catch-all matches are recorded as unknown institution plus verified domain rather than an invented school.

## School requests

- Patient `GET/POST /api/v1/student-school-requests`: own request statuses and explicit submission of institution, domain and official website. Maximum three new requests per rolling day/account; repeated account/domain submissions reuse the existing request.
- Admin `GET /api/v1/admin/student-school-requests`: paginated review queue, grouped by domain/status.
- Admin `POST /api/v1/admin/student-school-requests/{id}/review`: decision, source/reason note, institution type and Nigerian-tertiary/domain-ownership attestation for approval. Requires subscription management capability and scoped route permission.
- One domain decision resolves its pending request rows. Concurrent repeated decisions conflict. Domain approval never grants any applicant mailbox eligibility. Existing blocks require a separate audited domain-policy change.
- New-domain notices go to **team@cozyhealth.org and cozyhealth.hq@gmail.com**. Decision notices go to applicants' current Cozy email addresses. Notification recipients do not gain dashboard permissions.
- Encrypted database email jobs are inserted with the request transaction and have bounded retries. Successful delivery markers prevent duplicate job handling from resending. Transport/commit ambiguity still has the normal at-least-once email limitation. Notices contain no mood/journal information.

## Store boundary

The existing Student tier and store purchase verification/ownership/restore/lifecycle infrastructure are retained. New `POST /api/v1/store/purchase-check` checks platform configuration, allowed product and current Student eligibility immediately before native checkout. `GET /api/v1/store/config` adds `platform_ready.apple/google` and `student_eligibility` (status, expiry and renewal availability), and retains separate `student_eligible`. The Flutter app checks readiness and calls preflight before starting Apple/Google billing. The plans panel prompts for expiring/expired email eligibility and refreshes it when returning from verification.

Only backend-verified store entitlements update subscription access. Pending/canceled/failed purchases and eligibility alone do not. Existing paid periods and verified renewals are honored after email eligibility expiry; new Student purchase initiation is blocked and re-verification is requested. Cozy does not pretend to cancel automatic store charges. Strict recurring-discount requalification requires a separately validated store-supported product design.

Developer accounts/products/credentials are unavailable. Billing remains disabled; configuration presence is only a readiness prerequisite, not proof of valid credentials, approved products, store policy acceptance or successful sandbox payment. Existing tests use controlled verifier/HTTP responses. No real product IDs, prices, free-trial offers, production credentials or store releases are created by this batch.

Before billing activation: do not configure Student free-trial offers under the paid-access policy; configure approved Apple/Google Student products and NG availability, localized prices/terms, app identifiers, account binding, Apple signing/root trust, Google service-account permissions and notification verification; test real sandbox purchase, delayed first verification across email-eligibility expiry, pending/cancel, restore, renewal, refund/revocation, acknowledgement and signed notifications on both platforms. Validate the final offer/base-plan configuration and expired-eligibility renewal behavior against both stores' current rules. Genuine payments remain last in the root build plan.

## Privacy and acceptance

School addresses are encrypted and verification data stays outside provider health-data interfaces. Codes never enter logs/analytics. Used challenges are removed immediately; expired/exhausted challenges are purged at least daily. Raw school addresses on eligibility records are removed ninety days after eligibility expiry. Protected unique email claims remain until account deletion. Closed school request rows are removed after ninety days; institution/domain configuration and minimal admin audit remain. No changes to financial retention are made. Public-launch privacy wording/retention legal review remains required.

Root `docs/HANDOFF.md` and the Student delivery record contain actual validation outcomes. Code tests do not prove real inbox/device journeys, sandbox transactions, iOS native compilation or public launch acceptance.
