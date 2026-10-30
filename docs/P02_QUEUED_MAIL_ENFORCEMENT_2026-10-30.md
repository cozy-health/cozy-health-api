# P02 queued-mail validity enforcement - 30 October 2026

Development only. General sign-in enrollment stays paused. Unrelated local edits are not part of this delivery.

## Reproduced defect

The installed Laravel Mailable/SendQueuedMailable path does not automatically invoke application-defined shouldSend methods. Earlier direct method checks established the validity predicates but did not establish their execution by a real worker. Eighteen expired/replaced/used code cases sent mail when run through actual encrypted database jobs; six current-code controls sent successfully.

## Repair

The shared TracksDelivery send method now invokes an optional shouldSend predicate before rendering the message or reaching SMTP. Rejected queued/sending rows become suppressed; terminal delivery outcomes are not downgraded. The worker completes without retries for a deliberately cancelled stale message. Mail without a predicate retains its normal delivery path. Tests inspect actual HTML/plain-text transported codes and the persisted outcome through the real database queue.

Coverage includes patient/provider reset and sign-in codes, patient email verification and provider connection invitations. Both-role welcome-queue failure tests preserve a created recoverable account and truthful response. Both roles also exercise sign-in queue failure without private credentials.

## Local final evidence

Focused final group: 59 passed / 459 assertions. Full suite after the final source/test edits: 918 passed / 6,718 assertions. Before/after logs are retained in the workspace output directory. QueuedEmailLifecycleTest is included in the complete existing MySQL CI contract subset; no earlier contract case was removed.

## Live acceptance boundaries

Use only two new owned Gmail aliases for public HTTPS signup/reset/verification/enrollment/fallback/invitation acceptance, plus one reserved .invalid address for a harmless real-provider failure attempt. Preserve all prior development recipients. Fixture credentials remain encrypted, never printed, and all sessions/test access must be revoked with fixtures disabled when acceptance ends. Do not alter the owner's actual account password or shared enrollment flags.

Brevo sandbox drop mode does not generate delivery logs and therefore does not prove a real failure callback. Report actual vendor outcomes separately from synthetic callback tests. Hosted CI, exact deployments and live results must be recorded in the workspace P02 delivery report after verification.
