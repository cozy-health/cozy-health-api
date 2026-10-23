# Consent, legal, age and guardian audit — 2026-10-23

This is a source and local-test audit of the development work. The workspace
`docs/BUILD_PLAN.md` remains the delivery backlog. This report does not approve
legal text, country availability, guardian assurance, activation or deployment.

## Verdict

Both areas have substantial implementation. Neither should be described as a
fully accepted, active release. Commit the existing implementation as a disabled
development baseline, then complete the acceptance work below before activation.

## Implemented

- Legal publication source and version archive on the website, generated API
  and mobile copies, immutable published versions, draft tracking separate from
  acceptance, current-version consent records, optional marketing, and audited
  withdrawal. Cross-repository generated artifacts match.
- DOB and residence collection, under-13 signup rejection, UTC birthday
  boundaries, completion for existing/social accounts, country changes and
  age-correction support paths. Ordinary profile updates cannot edit DOB.
- Guardian invitations, hashed single-use links, explicit email confirmation,
  short-lived restricted sessions, authorization/decline/dispute/withdrawal,
  expiration, replacement and adulthood handling. Guardian authorization does
  not grant access to private records or substitute for the user's own consent.
- Server restrictions for private patient operations and provider access;
  checks for jobs, reminders, audio/report access; app gate, local write/sync
  guards and minor offline restrictions. Recovery, deletion and safety routes
  remain reachable.
- Scoped staff case queue with assignment, recorded responses, approval-gated
  corrections, audit events, retention preview/pruning, additive migration and
  disabled-development release checks.

## Findings and ordered completion plan

### 1. Preserve restrictions across multiple support cases — required code fix

`AdminAgeCaseController::resolve` sets the account-wide `age_disputed` flag to
false on `correct_dob` or `clear_dispute` without checking other open DOB or
guardian-dispute cases. `AgeAuthorizationController::support` permits more than
one such case. Resolving one can therefore clear the account restriction while
another remains unresolved. Ending guardian authorizations still protects many
minors, but does not preserve an adult's dispute restriction.

Recompute the account restriction from remaining unresolved blocking cases in
the same account transaction. Define which case kinds/actions can clear each
restriction. Add regressions for two cases resolved in either order, ordinary
closure, adult/minor accounts and concurrent reviewer actions. Pass condition:
one resolution cannot reopen an account with another blocking case.

**Follow-up implementation (2026-10-23):** the resolution now checks other open
blocking cases under the existing account lock, using a locking current read
to avoid a stale MySQL snapshot. Blocking case kinds are shared with case creation.
Privacy-only cases cannot clear age restrictions or change DOB. Ordinary closure
preserves the restriction; an approved appeal can clear it when no blocking case
remains. New regression coverage exercises adults/minors, both resolution orders,
correction/clearance, privacy rejection, appeals, account isolation, reviewer
assignment/stale decisions and transaction rollback after an audit-write failure.
This closes the identified source gap; isolated MySQL concurrency acceptance
remains part of step 5. Steps 2–6 remain outstanding.

### 2. Make errors and recovery actionable — required client work

`AgeEligibilityGate::_run` replaces all failures with one generic message. It
hides server details such as resend throttling, invalid country/input and
invitations being unavailable. Display safe field errors, meaningful status
messages and retry timing; preserve input. Show human-readable guardian states
and link/session expiry guidance rather than raw status identifiers.

The guardian website keeps the review in component memory and consumes the link
on confirmation. Reloading does not restore the review from the HttpOnly session;
the original fragment has already been removed. A lost confirmation response
also needs a recovery path. Implement bounded session-review recovery, or an
explicit, tested new-link recovery flow. Acceptance: reload, timeout and network
failure never imply success or leave an unexplained dead end.

### 3. Complete account-change and consent failure acceptance — required tests

Existing service tests cover delayed eligibility responses and old-session 403s,
but the age gate itself has no dedicated widget test. Its form controllers are
not cleared on session changes, and action completions are not generation-bound.
Add account-change handling for guardian contacts/support drafts and late action
feedback. Test a switch during submit, consent loading/acceptance/withdrawal,
foregrounding, offline/unknown network state and token rotation. Verify cached
adult allowance remains scoped to its account and cannot revive a known denial.

`ConsentGate` retains its draft-rollout load-error behavior. In enforced mode,
exercise failed/slow consent loads and the handoff from the age gate, so private
content is not displayed while required acceptance is unknown. Server denial
alone is insufficient visual/privacy acceptance. Add retry/loading states as
needed, preserving public support and safety navigation.

### 4. Review the actual screens — required UI acceptance

The age gate uses standard Scaffold/ExpansionTile/FilledButton surfaces; source
review and service tests do not prove it matches the approved Cozy Health UI.
Review DOB/country, pending/expired/declined/disputed/authorized states, consent,
withdrawal and staff forms in every supported app theme, light/dark/high contrast,
small screens, large text, keyboard and screen readers. Check destructive-action
confirmation, focus/error announcements, tap targets, long policy text and no
clipped fields. Collect full-screen references for design approval before
reworking these screens. No new UI design is claimed approved by this audit.

### 5. Prove the complete development workflow — required integration evidence

Run synthetic accounts through signup/social/legacy completion, invite, inbox
delivery, confirm, authorize, decline, dispute, replacement, expiry, withdrawal,
residence change, consent version change, correction/appeal and turning 18.
Verify provider/file/report access and queued jobs stop after restriction while
private records and unsent drafts remain preserved. Test current signed clients,
minimum-version/old-client behavior and account switching on physical devices.

Local API tests use SQLite memory. They do not prove MySQL row-lock behavior.
Add isolated MySQL race tests for invite/replace/decision/withdrawal, private
operations versus revocation, staff correction and prune races. Exercise additive
migration, controlled rollback and retention/deletion including backup expiry
in development. Do not run mutation collections against production.

### 6. Obtain activation decisions — owner/operations prerequisites

Seven website legal documents remain unpublished: consumer Terms, Privacy,
Health-Data Consent, Marketing, Guardian Notice, and provider Terms/Privacy.
Four informational documents are published. Final editorial copy is not an
effective acceptance document. Obtain approval, publish the approved versions
through the established process and recheck all generated artifacts/consent flows.

Approve supported countries, actual guardian assurance method, retention period,
correction procedure, named reviewers/backup and appeal coverage. The implemented
method proves email access plus a declaration; it is not identity/guardianship
verification. Where stronger assurance is required, implement the approved
method before enabling that country. Record real approvals, not test placeholders.

Keep enforcement/invitation/minor-readiness rollout defaults disabled until the
corresponding development acceptance gates pass. A compatible client/minimum
version and an explicit subsequent activation decision are required. Once active,
disable minor readiness/invitations to stop access; disabling enforcement would
reopen it. Production changes require separate explicit authorization.

## Local verification

- Focused API suite: 59 passed, 281 assertions (age/guardian, release checks,
  document consent and consent-related access checks), SQLite in memory.
- Full API suite: 702 passed, 4,918 assertions, SQLite in memory, using
  `php -d extension=C:/tools/php82/ext/php_gd.dll vendor/bin/pest --compact`.
  The initial run without GD had 695 passes and seven image-fixture failures;
  all passed after enabling the installed extension for the command only.
- Website: TypeScript check passed with strict and unchecked-index checks;
  11 tests passed. Legal synchronization check passed across website/API/mobile.
- Dashboard: TypeScript check passed with strict and unchecked-index checks;
  27 unit tests passed. These do not constitute reviewer UI end-to-end acceptance.
- Flutter: 11 targeted tests passed (age fields, eligibility service and consent
  gate); scoped analysis of ten feature/test files passed with no issues. This
  was not a full Flutter suite or a physical-device acceptance run.

## Matching development commits

- Mobile: `e372d5b` — age/guardian flow and consent safeguards.
- Website: `900aeb2` — guardian relay/pages and synchronized legal notices.
- Staff dashboard: `3b135eb` — scoped guardian support queue.
- API baseline: `e74c48c` — disabled authorization and legal consent safeguards.

These commits preserve the implementation as a disabled development baseline.
The initial audit did not implement the completion plan. The follow-up described
under step 1 implements the dispute fix only; steps 2–6 remain outstanding.

## Dispute-fix verification follow-up

Before the fix, all ten new multiple-case/privacy-case regressions failed against
the baseline. After the fix, the age/guardian suite passed 37 tests with 216
assertions, including 13 new regressions. Tests use SQLite in memory. Concurrent
MySQL transactions and physical-device workflows remain separate acceptance gates.
The complete API suite also passed: 715 tests, 5,022 assertions, with the installed
GD extension enabled for the test command. No activation flags or live records
were changed.

No live migration, email, deployment, policy publication or activation was
performed for this audit. Existing historical device/inbox/rollback claims have
not been promoted to new evidence.
