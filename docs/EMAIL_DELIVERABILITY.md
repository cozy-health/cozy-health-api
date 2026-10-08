# Transactional email and delivery checks

Use the shared SMTP settings on both API and queue services. The sender defaults to `noreply@cozyhealth.org`. Mail is queued on `email`; reset and verification codes expire after 15 minutes. Monitor queue delays before launch. Test jobs should use addresses owned by the operator.

## DNS (manual, not applied by this change)

- Proposed SPF: `v=spf1 include:spf.brevo.com ~all`. Review Brevo's current instructions for your account first. Shared-IP domain authentication may not require an SPF change. Merge with any existing SPF record; never publish multiple SPF records.
- DKIM: copy the exact TXT/CNAME records from the Brevo domain-authentication dashboard. Do not invent selectors or keys.
- Proposed DMARC: `v=DMARC1; p=quarantine; rua=mailto:dmarc@cozyhealth.org`. Create/monitor the reporting mailbox and verify legitimate senders pass alignment before applying quarantine.

Sources: https://help.brevo.com/hc/en-us/articles/12163873383186-Authenticate-your-domain-with-Brevo-Brevo-code-DKIM-DMARC and https://help.brevo.com/hc/en-us/articles/4414335084434-Merge-multiple-SPF-records

## Webhook

Create a transactional webhook for `/webhooks/brevo` with bearer authentication. Set the same random secret (at least 32 characters) in Railway `BREVO_WEBHOOK_TOKEN` and Brevo's `auth.token`. The receiver checks `Authorization: Bearer ...` with constant-time comparison and fails closed if unset. This is a shared bearer token, not an invented HMAC signature scheme. See https://developers.brevo.com/docs/secured-webhooks.

Hard/soft bounce, spam/complaint, unsubscribe and blocked events are acknowledged idempotently. Hard bounce marks email_invalid; spam/unsubscribe records email_unsubscribed_at. Duplicate retries do not duplicate rows. Bounces retain event names/timestamps and a keyed deduplication hash, not recipient addresses or raw payloads. Account erasure cascades through user-linked bounce rows and verification codes. Unknown provider events are acknowledged without storage.

## Templates and future flows

HTML and plain-text alternatives cover reset, reset confirmation, verification, welcome, account deletion and quiz sharing. No quiz answers or scores are emailed. Phase 19 must provide an authenticated expiring review link and explicit sharing consent before invoking QuizShareMail. Branding uses the Cozy Health wordmark because no backend logo asset exists.

Authenticated verification endpoints: POST `/api/v1/auth/email-verification/resend` (3/hour/user) and `/verify` (6-digit code; attempt throttled). Password reset additionally limits requests to 3/hour/email across IPs; hashed limiter keys avoid raw addresses in cache keys. Welcome is queued on registration/first social login, which already creates an authenticated session. Account deletion confirmation carries no deleted User model.

## Live verification — pending deployment

Send password reset messages to operator-owned Gmail, Outlook, Yahoo and ProtonMail inboxes. Record inbox/spam placement and inspect SPF/DKIM/DMARC results in message headers. No live messages or DNS changes were performed during implementation; unit/feature tests use a local log transport or Mail::fake(). Delivery and inbox placement are not verified yet.
