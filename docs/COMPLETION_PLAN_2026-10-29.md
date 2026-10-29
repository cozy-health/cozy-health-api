# Cozy complete development build plan ? 29 October 2026

This is the current complete work order, incorporating the approved full plan and Premium/Student dashboard correction. Historical registers preserve evidence, not instructions to rebuild retired features. Work includes both unfinished implementation and acceptance of existing foundations.

## Verified baseline and release boundaries

- Mobile develop d843abd511fd983298599bea52db55456883a044: local analyzer exit 0; 1,178 tests passed and one existing skip. Hosted Android 40861175326 and unsigned iOS 40861175272 completed all build/artifact steps. These are not physical-device acceptance.
- API develop 1cacb4e2ebd10e8dbe9cba81ed7d15f1592b3ad2: previously verified 855 local tests and hosted checks. Current production backend has not received the later development work; candidate rollout/migrations require review.
- Production website release 46cc5b937eb6579749d468065a1f5b952ea538f7 was separately authorized and verified. Branding, plans and all ten legal pages are live, effective 29 October 2026; original store-button styling is retained.
- API consent activation remains held by unresolved readiness evidence. Staged sign-in client/provider integration is unfinished. Store billing/accounts remain last. New work stays on development; prior website publication does not authorize new production changes.
- Session/account and streak foundations are implemented. Preserve them and complete remaining acceptance rather than assuming every phase below is wholly missing.

## Approved benefits and pricing

| Benefit | Standard | Verified Student | Premium |
|---|---|---|---|
| Written journals | Yes | Yes | Yes |
| Mood check-ins, saved history and streaks | Yes | Yes | Yes |
| Emergency resources | Yes | Yes | Yes |
| Community | Read-only | Full participation | Full participation |
| Voice | No | Yes | Yes |
| Provider connection/chat and journal sharing | No | Yes | Yes |
| Assessments and challenges | No | Yes | Yes |
| Complete Trends/Insights dashboard | No | Yes | Yes |

The trends_dashboard entitlement covers Insights home, mood trends, triggers, sleep/activity analysis and monthly reports, including direct links and derived API data. Standard retains basic Home, saved records, check-ins and streaks. Preserve raw-record/export rights on expiry; derived insights require current access. Use account-scoped, expiry-bound offline permissions. Existing staged plan enforcement must be verified explicitly; source changes alone do not prove the development flag is enabled.

Historical monthly references only: Student NGN 5,000; Standard NGN 6,500; Premium NGN 10,000. Approved USD amounts are pending owner input. Never relabel/convert these figures automatically or advertise the legacy annual discount. Real checkout prices come from native store products when accounts/products are configured. No country picker. Free is retired. Provider/institution billing and professional provider verification remain excluded. Institution onboarding/eligibility codes do not create a paid receipt.

All future implementation, checks, pushes and deployments are development-only unless a new specific production release is authorized. Store billing remains held. This document defines approved behavior, not completed acceptance.


## Documentation and completion evidence

Update the master build plan, completion checklist, progress report and original R/F/B crosswalk. Keep every original item classified exactly once and record this dashboard addition separately without renumbering historical requirements. The actual development app packages, website benefits and API permissions must match; documentation-only changes cannot close that requirement.

Use verified commits, environment IDs, runtime/inbox/device evidence and completed CI outcomes. Mark pending/failed/unrun work honestly. Preserve unrelated local edits. Reproduce failures before repair and run the complete affected CI toolchain after final edits.

## Execution order and completion gates

### P00 — Reconcile contracts and evidence

Inventory all four develop repositories, changes, reachable actions/routes, flags, contracts and CI prerequisites. Preserve unrelated edits. Reproduce reported faults before fixes. Resolve direct/sync validation parity, false success, registration atomicity/session issuance, crisis-flag differences and unsupported caller paths. Record exact source and development configuration, not old snapshots. Keep current failures separate from superseded CI records. Review the existing skipped Flutter case and supply or explain its coverage.

Done when every enabled action has a real outcome or an honest unavailable state, current contracts match the callers, owner/role failures have regression coverage and the four-repository baseline is recorded. Depends on no store account.

### P01 — Finish offline/account integrity and streaks

Create one typed, account-scoped streak repository/state used by Home and detail. Cache confirmed progress with its local date/timezone/version; preserve queued mood check-ins, distinguish pending versus confirmed progress, and reconcile offline/rest-day projections with the server. Home must display completed/protected/pending dates consistently. Keep the approved bank/earning rules, retained achievements and no retroactive credits.

Test duplicate and late saves, edit/delete, weak/offline networking, close/reopen, logout, delayed old-account responses, account switching, multiple devices, timezone/DST/midnight, restore and tombstones across writable domains. Diagnose the originally stuck phone when available; do not discard its queue to make the badge disappear.

Done when account A cannot expose/submit data under account B, retry cannot double-count/spend, acknowledged changes survive restart, and offline Home/detail converge after sync. Depends on P00; no store account.

### P02 — Finish sign-in and branded email

Connect patient/provider signup, security-question enrollment, new-device/14-day challenge, trusted-device proof storage, forgotten-answer email fallback, settings changes and session revocation to the existing backend. Keep correct-password-first, hashed answers, challenge/expiry/attempt/resend bounds and no authenticator app. Do not reset passwords using questions alone. Test Google development OAuth where credentials are available; hold intended Apple identity acceptance for S01.

Trace registration/reset/verification/invite/challenge emails through API -> queue/worker -> Brevo -> delivery/bounce -> owned inbox. Reuse Cozy's branded HTML/plain-text design for every customer template. Exercise token expiry, resend, duplicate prevention and truthful failure after a password is already saved. Use owned development inboxes/designated test recipients, never guessed random public email addresses. Retain a separate safe admin-access review.

Done when both patient/provider clients complete positive and negative journeys, account identity stays correct, real inbox evidence exists and the compatible flag rollout/recovery is rehearsed in development. Prerequisites: P00/P01, inbox/provider configuration; no store account for password/questions/email.

### P03 — Ordinary consent, support and privacy administration

Remove obsolete guardian gates/copy/routes from active journeys while preserving historical records subject to approved retention. Keep ordinary health/privacy consent: fail closed for protected content if required consent is unavailable, explain errors and offer retry; preserve approved emergency access. Reconcile mobile/web/API document versions, drafts versus effective publication, withdrawal and restart/offline recovery.

Implement/finish private support threads with replies, reopen/retry and notifications. Staff notes are staff-only internal records, never customer replies. Enforce separate permissions for support, Student evidence, moderation and verified privacy export/correction/deletion; hiding a button is insufficient. Audit actor/action/outcome without copying journals or answers into logs.

Done when cross-role/account access is denied at API and UI, drafts recover, users see actual replies, staff notes never leak and required-consent errors never reveal protected content. Depends on P01/P02; policy copy and named responders may require owner input, not store accounts.

### P04 — Complete core app and approved UI

Implement remaining approved icons, explanatory wording, centered popups, navigation/back buttons, packages without country selection and ordinary permission requests. Keep assistant and mascot removed. Review journal entry/actions/tags, microphone permission/recording errors, Quick introduction, Ready to begin, About this feature, identity confirmation, sync notices, provider-required and sharing-off dialogs against the approved previews. Recheck acknowledged urgent-support/guideline notices across fresh launches; draft or unchanged guidelines must not announce a new update. Preserve existing lifecycle fixes rather than rebuilding them without reproduction.

Verify the five-step mood flow, drafts/history/search, journal create/edit/delete/audio/locks, provider sharing, breathing visible timer/start/pause/background/recovery, Home/insights/calendar, rolling 24-hour affirmations/read acknowledgement, actual share/device JSON output, and cloud archive/monthly PDF jobs where approved. Never label partial device JSON as a complete cloud export. Test private audio/PDF/avatar ownership, codecs, FFmpeg/FFprobe, ranges and temporary-file cleanup.

Done when each enabled control has its intended persistent effect and small-screen, keyboard, all supported themes/accents, 200% text, screen-reader and reduced-motion checks pass. Take review screenshots before any further unapproved redesign. Depends on P01/P03; no store account for implementation/simulator testing.

### P05 — Students, institution offers and plan access

Preserve locked submitted applications; show decline reason, allow a new application or another plan, and prohibit editing decided evidence. Confirm current immediate approved/rejected deletion, immediate access revocation on decision and durable physical-delete retry; expire abandoned evidence after 14 days. Finish approved 12-month eligibility, renewal opening 30 days early, 30/7/1 reminders, resubmission/reverification, reviewer concurrency and paid-period preservation. Students must prove current enrollment using accepted recent documents; email confirmation alone is not eligibility.

Finish institution/department sales intake and admin category, email-only cohort visibility and unique expiring single-use email-bound invitations/offer codes. Institutions see permitted student emails, not health data, bills or payment totals. Institution payments, training and provider charging remain excluded. Students see their own information.

Add the trends_dashboard permission for Premium and verified Student. Protect all Insights routes and detail screens, related API responses and Home derived-trend previews. Keep Standard basic Home/history/streaks and existing record/export rights. Update actual development package cards, website comparison and API entitlements together; check direct links, expiry, offline cache, account switching and denied/loading/retry states.

Enforce Standard/Student/Premium rights on API and clients: Standard written journals/moods/emergency and reduced community; no Standard voice/provider chat/assessments/challenges. Premium includes the approved provider connection/chat, assessments and voice benefits; eligible Student has Premium benefits. No Free tier, fabricated prices or country picker. Preserve readable/exportable existing records and emergency access on expiry. Test entitlement logic with isolated development fixtures; invitation/eligibility codes must not fabricate paid receipts. Final real price discounts/paid offer redemption await supported store products in S01.

Done when reviewer lifecycle, cleanup failures, renewal/expiry and code misuse/account binding pass, with no privilege or institution-data leak. Depends on P02/P03; student evidence types, content and named reviewers are owner inputs where not already settled.

### P06 — Provider and community round trips

Finish the Premium option to choose another provider after seven days with no reply to the first unanswered patient message. Define/reuse a server-authoritative unanswered clock, exclude drafts/failed sends, handle late replies and concurrent choice; no automatic transfer or guaranteed response. Confirm permission boundaries on any new provider relationship.

Run patient <-> provider invitation/accept/decline/expiry, roster, profile, messages/audio/resources/assignments/notes/PDFs, journal/assessment sharing, selected/all-off grants, revoke/unlink and retained-copy journeys. Test wrong account, cross-provider access, restart, cache clearing, ordering/retry and offline behavior. Provider professional verification and billing are not Cozy implementation tasks.

Finish community feed/post/comment/reaction/group/image persistence, nonclinical polls, anonymous public payloads, metadata stripping and fail-closed moderation, guideline version/read-once behavior, shared Home notification bell and direct post destinations. Test report/block across every view, ban/appeal consistency, review outcomes and audit. Remove unavailable DMs/follow/search/group-creation controls as approved. Verify invite-a-friend produces a real approved link and honest outcomes, without fabricated referrals.

Done when synthetic patient/provider/admin/community journeys pass on the same development stack and staff coverage/escalation is explicit. Depends on P02/P03/P05, with P04 sharing/audio support.

### P07 — Real content and admin/provider dashboards

Finish actual article catalog/category/search/pagination/body/share/save/offline refresh and publishing rights/attribution. Use only approved assessment instruments/questions/provenance/scoring/bands/versioning/recommendations/disclaimers; no invented clinical text or normal-mode test fixtures. Test attempts, immutable versions, offline provisional results, invites, severity handling, selected sharing/revocation and PDFs.

Implement the approved colorful admin/provider overview designs with genuine authorized metrics, filters/date ranges, empty/loading/error states and small-cohort protections. Include correct Cozy mood illustrations, institutions/Student review, consistent moderation and support/private-notes tools. Admin controls require CSRF, capabilities, concurrency protection and audit. No fabricated sample metrics in normal mode or obsolete provider-billing panels.

Done when publish -> app consumption -> patient result/share -> provider/admin view is verified with rights and role boundaries. Depends on P03/P05/P06; approved content is a possible owner dependency, not a store account.

### P08 — Delivery, reminders, links and emergency contacts

Connect one notification center/bell and reliable read state across Home/community. Test Android Firebase push permission/denial/revocation, tokens/rotation, foreground/background/terminated taps, multiple devices and logout/account isolation. Prepare iOS configuration now; real APNs delivery is S01. Reconcile local/remote mood/journal/streak/affirmation reminders, quiet hours, approved caps/spacing, read acknowledgements, timezone/DST and cancellation after withdrawal/deletion.

Verify owned-domain web/Android links, assessment invitations, referrals, signed-out/cold/warm handling, invalid/reused/expired tokens, allowed hosts and genuine destinations; final store-install/Apple association acceptance is S01. Finish emergency-recipient confirmation, withdrawal, generic private email, duplicate/race/queued-withdrawal protection, bounded retries and account/global limits. Keep call/message fallback and emergency access for every plan. Sending stays disabled until operating limits and consenting recipients are established; no rescue/continuous-monitoring promise.

Done when real development Android delivery, approved inbox flows and failure recovery have evidence. Do not count an API send acknowledgement as phone/inbox delivery. Depends on P01/P02/P04/P06; phones/inboxes and approved emergency limits are separate dependencies.

### P09 — Website, cleanup, security and recovery

Development APK update continuity is an explicit gate: the downloaded `3a5fc30` and `9859636` APKs have different verified signing certificates. Establish stable development-only signing custody in CI and verify update installation without losing account-owned drafts/outboxes. Older ephemeral signing keys cannot be assumed recoverable; document a safe legacy-build migration after confirming synchronization or an appropriate backup. Do not tell testers to uninstall blindly. This does not require store developer accounts and remains separate from production release signing.

Finish website forms/actions/footer/support/legal links and reviewed ordinary-consent wording; retire active guardian claims, obsolete authenticator/assistant/external-connections/Digital Wellbeing/unavailable features. Review callers before removing duplicate/unused code and preserve user/historical data. Optional connection sound is not a blocker.

Review current lockfiles/advisories and authorization/IDOR, injection/XSS, CSRF, token/enumeration/rate/upload abuse and anonymous-data leakage. Validate trusted DB TLS/CA, encryption/private storage, current/next pins, key custody/rotation, privacy-safe logs/notifications/telemetry and environment separation. Recheck certificate/configuration deadlines against current evidence; do not treat old notices as a newly verified deadline.

Run isolated account deletion and storage failure/retry/hold tests, worker/scheduler liveness and restart recovery, backup/restore plus deletion/withdrawal replay, additive migration/rollback compatibility and representative development load/failure tests. Set workload and recovery/performance budgets with the owner rather than inventing passing thresholds. Obtain applicable retention/vendor/transfer/privacy documents and named support/moderation/Student/incident owners and backups. No new training product or provider-verification workflow.

Done when findings have fixes or explicit owner decisions, recovery is demonstrated and the website/API/app policies match actual behavior. Depends on the relevant prior workflows; these are not store-account blockers.

### P10 — Monitoring last among implementation work

Finish existing Sentry/PostHog/PagerDuty development setup after P00-P09. Confirm actual accounts/integration access and current free-plan limits before configuration. Redact health text/answers/tokens, respect optional analytics choices and keep development separate. Test single errors, multiple-user outage/failure rates, queue age/failures, mail/push/storage/deletion faults and actionable correlation without health payloads.

Send a clearly labeled developer test alert to the assigned developer destination: loud push first, fallback email; verify acknowledgement, repeat/escalation and recovery. Record OS notification/DND settings and any platform limits; do not promise a free tier or app can override silent/DND without evidence. Escalations belong to named developers/responders, not users.

Done when a controlled development fault produces a received actionable developer alert and recovery, not merely a configured integration. Requires service access and developer phone, not Cozy store accounts.

### P11 — Final devices and pre-account completion audit

Use the developer's phones at the final acceptance stage, as requested. Complete Android install/upgrade, keyboard/navigation, recording, biometric/PIN, denial/revocation, low storage, offline recovery, background/terminated behavior and all applicable end-to-end workflows using exact artifacts. Run iOS simulator acceptance; perform available common iPhone tests only with actual Mac/signing/phone access. Carry unavailable Apple push/signing/store journeys to S01 explicitly.

Pass 1: walk every surviving R/F/B requirement against source, runtime, inbox/browser/device evidence and dependencies; reproduce/fix all observed task-scope failures. Pass 2: start from original IDs, reverse-check every enabled route/screen and prior failure, inspect scope contradictions, all roles/themes/large text, cleanup and negative cases, and confirm no uncategorized item remains. Re-run affected checks after fixes. Keep unrun/device/owner-blocked items open.

Done when every item is verified, explicitly owner/device-blocked, retired, optional-deferred, store-dependent or future separately authorized work. Never call the whole app complete from green unit tests alone. Do not stall unrelated work while waiting for content, phones or approvals.

### S01 — Developer accounts and genuine store acceptance, last

Once the owner supplies access, configure final identifiers/signing/APNs, Apple login, actual store products/localized prices and eligible offer mechanisms; test signed iPhone push and native associations. Complete store sandbox purchase/acknowledge/restore/renewal/cancel/expiry/refund/revocation/grace, wrong-account/forged/replayed/out-of-order notifications and reconciliation. Use the stores' receipts; no duplicate Cozy tax invoice or country picker.

Complete account-dependent store age/content settings, privacy/Data Safety declarations and specifically authorized testing-channel install/update journeys. Store copy, permission wording, evidence packets, update compatibility and beta criteria can be drafted and tested earlier; only their final identity/distribution checks wait here. Paid store accounts do not resolve missing content, policies, staffing or physical testing automatically.

Publishing, production promotion, enabling production flags or inviting a live cohort remains a separate specifically authorized decision (B109-B121), not implied by this plan.


## Outstanding owner/external inputs

Exact monthly USD Student/Standard/Premium amounts; any missing approved assessment/article material and rights; vendor/retention/13+ privacy readiness evidence; actual operating limits and named support/moderation/incident coverage; final device/signing/integration access. These inputs hold only dependent work, not unrelated development.
