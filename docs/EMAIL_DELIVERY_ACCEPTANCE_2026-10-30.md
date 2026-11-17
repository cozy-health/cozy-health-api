# Development email delivery and bounce acceptance - 30 October 2026

This development-only batch closes provider/patient bounce parity and adds a guard before connecting a shared Brevo account's webhook. General sign-in enrollment remains paused. Production is outside this batch.

## Behavior

- Authenticated permanent-bounce events now mark either account role's address unusable; provider reset requests keep the same generic response as unknown accounts but queue no mail to an invalid mailbox.
- Queued provider resets and patient/provider sign-in codes recheck invalid-address state before dispatch. A transport guard also prevents tracked service notices reaching a known invalid mailbox and records a suppressed outcome without message contents or recipient addresses.
- Provider bounce records are independently owned and cascade on provider deletion. Shared-mailbox patient/provider records stay independently deduplicated, including a duplicate callback after the patient account is erased. Existing patient event keys are preserved.
- Temporary bounces do not mark an address permanently invalid. Optional-mail spam/unsubscribe timestamps do not disable authentication email. Invalid-address state is server-controlled and not mass assignable or added to provider API output.
- New nullable provider ownership on bounces and provider delivery-state columns are additive. Old rows remain valid. A deployment must apply the migration before restarting workers on this version.

## Development-only webhook boundary

SMTP messages from the verified development project/environment/host carry X-Mailin-custom: cozy_environment:development. Existing safe custom metadata is retained. The authenticated development callback ignores unmarked, differently marked, malformed or unapproved-recipient events before delivery logging or recipient lookup. A mismatched development identity fails closed. This keeps other environments' events out of the development database even if Brevo shares an account.

Required endpoint: https://api-dev.cozyhealth.org/webhooks/brevo. Use a dedicated Bearer credential in the development API's BREVO_WEBHOOK_TOKEN. The generated value was created through stdin with skip-deploys; no secret belongs in this file, source control, logs or chat. Preserve existing production hooks and keys.

Configure a Transactional Email webhook with delivered, hardBounce, softBounce, blocked, spam, invalid and unsubscribed events; Brevo's invalid-email payload is invalid_email. Disable batching. Authentication is Authorization: Bearer followed by the development token. The owner may configure the dashboard manually or authenticate the official CLI/API; SMTP credentials alone do not grant management API access. Do not save/enable the callback until the tested API and worker versions have deployed.

Official contracts: [transactional events](https://developers.brevo.com/docs/transactional-webhooks), [custom SMTP metadata](https://developers.brevo.com/docs/send-a-transactional-email), [webhook authentication](https://developers.brevo.com/docs/secured-webhooks), [CLI](https://developers.brevo.com/docs/cli-reference).

## Evidence so far

Before repair: ten new bounce tests failed; seven of eight environment-isolation tests failed. Focused final group: 75 passed / 512 assertions. Full final local API suite: 891 passed / 6,546 assertions. The actual transported MIME message, blocked transport, role/account isolation, replay/deduplication and permanent-deletion cascade are checked locally; these are not real Brevo delivery/bounce callbacks.

Hosted release/MySQL and concurrency checks, exact remote commit and development deployments must be recorded after push. No pending job is a pass. Live Brevo callback receipt, complete registration/reset/invitation inbox cases and compatible-client controlled rollout/recovery remain open. Do not send test failures to other people's addresses or mark a real customer's address invalid to demonstrate this feature.
