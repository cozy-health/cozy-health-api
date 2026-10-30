# P02 development acceptance - 30 October 2026

The requested development sign-in/email batch is complete. General development enrollment remains paused by design; no production action or store-account work occurred. This is API/provider-bridge and automated-client evidence, not physical-phone acceptance.

## Actual mail dispatch repair

The installed Laravel Mailable worker does not call application shouldSend methods automatically. Direct predicate tests in earlier deliveries did not prove worker enforcement. Real encrypted database-queue tests reproduced eighteen stale messages being sent. The shared dispatch method now enforces code validity before rendering/SMTP and records deliberately cancelled queued/sending rows as suppressed without overwriting a terminal outcome.

Twenty-four real-worker cases cover current/expired/replaced/used codes across patient/provider resets and sign-in, patient verification and provider invitation mail. Current controls check actual transported HTML and plain text. The deployed worker independently processed one invalidated synthetic sign-in job as suppressed, not sent or left queued.

## Live public HTTPS journeys

Separate owned patient/provider fixtures registered successfully and duplicate registrations were rejected. Real inbox codes supplied by the owner completed patient email verification and both-role password resets. Replayed codes were rejected, prior sessions revoked and new-password logins passed. Earlier naturally expired verification/reset codes were rejected without incrementing wrong-guess counters or changing passwords; normal resends succeeded.

Both fixtures entered a controlled cohort while the public flag stayed false. Private requests were blocked before enrollment. Delivered codes enabled three-question setup; trusted-device reuse, new-device questions and fixture-scoped simulated 15-day inactivity reverification passed. Real email fallback recovered both accounts and rejected replay. A provider code submitted against the patient challenge was rejected and subsequently accepted only for its proper provider challenge.

The actual dashboard-dev provider bridge passed question login, pre-verification private denial, secure HttpOnly scoped cookies, exact account identity, no-store, stale-scope rejection, CSRF denial, preserved session after denied logout, normal logout and revoked-cookie/session denial. No browser credentials, cookies or answers were printed or retained in plaintext.

The patient sent a real provider invitation with every sharing flag off. Immediate resend was rate-limited. The provider previewed and accepted the inbox code, and replay was rejected. No professional provider verification or billing was introduced.

## Real vendor and worker outcomes

A single harmless message to a reserved .invalid address produced a genuine Brevo soft_bounce event and an authenticated callback. Application delivery UUID 0ddfbc90-8924-4de0-8f30-8f728498eb45 became bounced at 15:18:44 UTC. Brevo metadata independently showed the soft bounce at 16:18:39+01:00. This proves a real failure callback, not every vendor failure subtype; hard-bounce behavior is covered by local/MySQL tests.

A deliberately cancelled worker job, UUID 103f6d51-4d9e-40ad-a56b-87121804eebc, became suppressed at 15:24:33 UTC without mail delivery.

## Validation and cleanup

Queued-mail repair commit 2757ba8475b799f470f94d88ccfeca350bec8619 passed local 918/6,718 and hosted 918/6,719, plus MySQL 184/1,450. Hosted release 41078788159 and both exact development deployments passed. The additional final both-role rollout-enabled signup cases cover no access/refresh token before email proof and explicit enrollment before private data. Final local suite: 920 passed / 6,748 assertions. Final completion-commit CI/deployment evidence is retained in the workspace P02 report after push.

The original owner's actual password/account was not changed. The isolated fixtures were disabled; all their access/refresh sessions and known devices revoked, temporary test access revoked, and the synthetic connection revoked. Pending passwords, answers, challenge tokens and device proofs were removed from the encrypted checkpoint, leaving a secret-free result. Temporary recipient additions are removed once the development email queue is empty; all original recipients remain preserved.

## Held separately

General enrollment activation remains a separate compatible-client rollout decision, with physical Android/iPhone install/update acceptance in P11 and Apple identity in S01. No gate for ordinary health-data consent was bypassed. Monitoring, real-phone acceptance and the other completion phases remain in the approved build plan. The real vendor failure observed was a soft bounce; do not describe it as a real hard bounce or claim every mailbox/vendor/email-client combination was exercised.
