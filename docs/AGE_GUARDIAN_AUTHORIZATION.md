# Age and guardian authorization

Local implementation, 2026-10-21. No deployment, migration, policy publication or minor activation is implied. The shared BUILD_PLAN remains the status ledger.

## Behavior and assurance

Password signup collects DOB after name and residence country before private onboarding. The server rejects under-13 registration. Google/Apple and existing accounts without complete age information enter restricted completion. Age uses UTC calendar dates; exact birthday boundaries and leap days use the same calculation as registration validation.

Ages 13–17 require current guardian authorization, their own effective health-data consent, an approved country, recorded assurance/retention approvals and health-data readiness. DOB is declared information; email confirmation establishes inbox access, not identity or guardianship. No evidence-upload or identity-verification service is implemented. Keep countries requiring stronger assurance disabled until an approved method is implemented.

Guardian authorization is separate from provider sharing, paid billing, emergency contacts, marketing and patient consent. It never gives a guardian private-record access. No guardian patient account is created.

## Interfaces

All paths below follow `/api/v1`; patient calls require the existing patient bearer session.

| Method/path | Behavior |
|---|---|
| GET `me/eligibility` | Own eligibility, declared DOB, request status and latest five team-case responses; no guardian email/token |
| POST `me/age` | Initial `date_of_birth` and ISO `residence_country`; cannot change a recorded DOB |
| PUT `me/residence` | Change residence and invalidate existing guardian authority |
| POST `me/guardian` | Invite/replace with `guardian_name`, `guardian_email`, `relationship` (`parent`/`legal_guardian`) |
| DELETE `me/guardian` | Cancel/withdraw own guardian authorization; preserve health records |
| POST `me/age-support` | Open `dob_correction`, `guardian_dispute`, `appeal` or `privacy` case with message; age/guardian disputes restrict access |
| POST `guardian/confirm` | Consume emailed token and issue a 20-minute restricted guardian session |
| POST `guardian/decision` | Explicit authorize/decline/not-guardian/withdraw; authorization requires adulthood/authority declaration and exact effective document versions |
| POST `guardian/withdrawal` | Generic response; email a fresh withdrawal confirmation link if eligible |
| GET `admin/age-cases` | Paginated case queue; real management token with `admin:guardians` |
| POST `admin/age-cases/{id}/resolve` | Assign or resolve; corrected DOB/dispute clearance needs approved procedure, authority check and verification reference |
| GET `crisis/public-resources` | Public verified regional crisis directory, with unknown-region fallback |

Required health-data consent can be withdrawn through the existing consent endpoint with `accepted=false`; accepting Terms is separate. Ordinary profile changes prohibit DOB updates. Registration does not accept health personalization once enforcement is active. Restricted profile reads return only basic identity to permit stored-session recovery.

OpenAPI and the existing Postman collection include the new interfaces. Unrelated historical contract drift remains R21. Use synthetic local accounts; never bulk-run a mutation collection against production. Do not persist real guardian tokens in collection examples or exports.

## Enforcement and mobile behavior

Patient middleware protects private routes by default; the explicit allowlist retains age/consent setup, basic identity, sessions/recovery, crisis resources, support and deletion. Protected patient operations lock the account within a transaction against guardian/consent/residence changes. Provider category, report, message and audio checks consult the patient's eligibility; provider lists filter restricted accounts. Reminder eligibility, FCM sends and emergency-email jobs recheck access at execution. Billing callbacks and entitlement administration remain separate from initiating purchases.

The mobile gate checks on session/route changes, foregrounding and every 30 seconds while active. Minors cannot use a persisted offline authorization: losing connectivity or failing a check locks private features. Public crisis/calming routes and existing safety-plan viewing remain reachable. Local health-data mutators and sync independently check eligibility; drafts/outbox entries remain encrypted and are not discarded. Adults retain their account-scoped offline allowance. Requests already delivered cannot be recalled; server restrictions protect subsequent operations.

Settings → Age & Guardian remains reachable for authorization management, country changes, own health-consent withdrawal and team requests. The existing consent gate is enforced for current effective documents when age enforcement is active, while support, legal, deletion and crisis routes remain accessible. Background/late responses cannot reopen a changed account or paused minor session.

## Cozy Health team duties

Normal email confirmation and authorization do not need a manual team approval. The team remains responsible for country/assurance approval, policy versions, trained reviewers, support ownership, privacy requests, safeguarding and appeals.

Only configured `GUARDIAN_REVIEWER_IDS` receive the dedicated management capability on a new admin login. Assign each case to a named reviewer; arrange an operational backup before absence. Assignment, case reads, corrections and decisions are audited. Use another reviewer for appeals where feasible. Close a case with a response suitable for the requester; omit confidential third-party information. The response appears in the app, with a generic email update attempted for verified accounts. Email failure never reverses a saved decision.

Closing a case with an ordinary response does not clear an age restriction.
An approved correction or dispute clearance can clear it only when no other
open DOB-correction or guardian-dispute case remains for that account. An appeal
can use the approved review procedure; a privacy-only case cannot change DOB or
clear age restrictions. The account and case locks serialize case changes, and
the remaining-case check uses a locking current read for MySQL. Real MySQL
concurrency acceptance is still required; local SQLite tests do not prove it.

Do not collect ID/evidence by ordinary email, put health records in case notes or infer authority from a checkbox. DOB corrections/dispute clearance are disabled without `AGE_CORRECTION_PROCEDURE_APPROVAL` and a case-specific `verification_reference`. This reference identifies a proportionate approved check; it is not the evidence itself. If no approved process can resolve a case, leave it restricted. Staff cannot override country, policy or global health-data gates. There is no promised emergency coverage or review SLA.

## Activation, mail and retention

The disabled staging deployment procedure, read-only release check and rollback
steps are in [AGE_GUARDIAN_STAGING_RELEASE.md](AGE_GUARDIAN_STAGING_RELEASE.md).

Defaults are off. Deploy the additive migration before using the new code. Release compatible mobile clients and set a tested minimum version before setting `AGE_ENFORCEMENT_ENABLED=true`. Staging must use its own API and website origins, accounts, keys and mail sink. Do not enable flags merely to pass tests.

- `GUARDIAN_INVITATIONS_ENABLED` also requires effective Terms, Privacy, Health-Data Consent and Guardian Notice, plus retention approval and a positive record-days limit.
- `AGE_HEALTH_DATA_READY`, `MINOR_APPROVED_COUNTRIES`, `GUARDIAN_ASSURANCE_APPROVAL`, `GUARDIAN_RETENTION_APPROVAL` and `GUARDIAN_RECORD_DAYS` separately gate minor access. The approval must cover the actual assurance method and every listed country. There is no all-country default.
- `GUARDIAN_WEBSITE_URL` selects the reviewed HTTPS website. Website `GUARDIAN_API_URL` must end `/api/v1/guardian/`, and `GUARDIAN_SITE_ORIGIN` must match the exact website origin. In production both require HTTPS. Do not point development guardian pages at production.
- Invitations expire after 48 hours; resend invalidates previous invitations. One/minute and three/day/account, recipient limits and IP limits bound sending. Mail failure is shown honestly; delivery/inbox acceptance still requires testing. Mail messages exclude private health content.
- Emailed token travels in a URL fragment, is exchanged only after an explicit email-confirmation action, and is stored hashed server-side. The website holds the restricted session in a Secure, HttpOnly, SameSite cookie, protects mutations by origin/header checks, disables caching/referrers/indexing and sends no analytics from guardian pages. Email link scanning does not grant authorization.
- Unused invitation contacts are scheduled for removal after 30 days. Active authorization evidence remains while needed for current authority; the approved `GUARDIAN_RECORD_DAYS` controls the period after authority ends and routine audit/case retention. No universal decision-retention period has been invented.
- `php artisan guardians:prune` previews. `--execute` requires approval and runs daily only when configured. Open cases hold related records; the team must review/resolve these holds rather than leaving them indefinitely. Existing account deletion cascades remove guardian/case/event rows. Verify backup expiry and deletion replay operationally before activation; a command does not prove backup erasure.

At 18, guardian authority ends; fresh user consent is required where outstanding. The status endpoint and daily cleanup retire old authority. Revocation/country changes preserve health records but stop subsequent protected operations. Roll back application code without dropping additive tables or deleting pending records; disable minor readiness rather than disabling enforcement to reopen access.

Physical signed-device, inbox, staging concurrency/rollback, country review, processor contracts, retention approval and support coverage remain activation acceptance gates. No live migrations, emails or deployments are performed by the local test suite.

## Verification handoff

The current check ledger is the shared BUILD_PLAN's October 21 age/guardian section. Web and dashboard strict checks, tests and production builds passed; final guardian-related Flutter tests passed 24 cases, and the repaired existing consolidation/surface harness passed 19. The broad mobile run is not clean: remaining failures concern existing mood-draft/layout/accessibility and assessment-history contrast work. Do not treat these targeted results as public-launch acceptance.

Provider checks authenticate the relationship before evaluating eligibility, filter restricted report lists, and return generic restrictions rather than exposing the patient's age/guardian state to other accounts. Final API tests cover unlinked-provider probing in addition to linked-provider revocation.
