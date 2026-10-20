# Consent language and default visibility review

Draft v0.1, 2026-10-09. Technical review complete; legal and clinical approval
pending. This review does not publish new patient copy or change existing toggles.

## Current contract

All four categories default off for new/reconnected links. Verified linked
providers can see first name, patient ID, sharing flags and assigned-resource
status without enabling mood/journal/quiz/note access. Authorized requests check
the active verified link, provider identity/status and required consent. Patient
note permission controls provider reading, creation and deletion. Journal endpoints
exclude bodies, transcripts and recordings; titles can still contain sensitive
text. Reports use the enabled categories and current link/consent snapshot.
Signed URLs alone do not bypass authentication or subsequent authorization.

The mobile labels currently read: Share mood entries; Share journal summaries;
Share quiz results; Allow provider notes. Journal help describes metadata only.
Withdrawal and revocation are available in My Provider. A reconnect resets flags.

## Proposed patient copy for review

“Connect only to a provider you recognize. A linked provider can see your first
name, patient ID, sharing choices and assigned-resource progress. All optional
sharing starts off. Choose each category below; using Cozy Health does not require
sharing with a provider.

- Mood entries: mood, intensity, dates, triggers and coping responses.
- Journal summaries: title, type, word count and date. Full journal text,
  transcripts and recordings are never shared through this portal.
- Quiz results: completed quizzes, answers/results made available by the service,
  scores and interpretations.
- Provider notes: allow this provider to read, add and delete their notes about you.

Your provider may generate a PDF containing categories you have enabled. Turn off
a category in My Provider to stop future access to it, or disconnect to stop all
provider access. You can inspect access history in the app. New requests follow
your current choices. Data already viewed, copied or downloaded cannot be
recalled. Withdrawal does not automatically delete earlier provider records;
approved retention rules and legal obligations govern those records. Reconnecting
starts with optional sharing off again. This feature is not an emergency channel.”

Before publication, check the exact quiz fields against the approved clinical
contract, explain historical versus future entries, insert the recipient identity,
privacy contact and notice link, and obtain counsel's legal-basis assessment.
Patient data-sharing permission alone is not proof of every statutory condition.
Review titles, quiz answers and provider-note visibility with the clinical advisor.

## Technical evidence and gaps

| Topic | Finding / evidence | Status |
| --- | --- | --- |
| Default-off and reconnect reset | ProviderClientLink attributes; linking and patient-consent tests | Verified locally |
| Isolation and per-category enforcement | ProviderClientReadTest, ProviderNoteAuditTest, ProviderResourceTest | Verified locally |
| Revoked signed report rejection | ProviderReportTest | Verified locally |
| Current patient controls | Mobile provider_screen.dart, provider_access_log_screen.dart | Code reviewed; device test pending to PD-7 |
| Existing copies | Cannot revoke bytes already delivered; proposed notice explains this | Clinical/legal approval pending |
| Consent proof | Current flags plus action/time/link audit; event lacks category, old/new value and wording version | Launch blocker |
| Terms acceptance | No provider terms/version acceptance record in current portal | Launch blocker |
| Verification delivery | Code generation exists; delivery absent | Launch blocker |

A consent-evidence follow-up must persist the actor, link, category, old/new value,
wording/version, effective timestamp and trusted request metadata in the same
transaction as the flag update. Preserve historical decisions through reconnect,
and determine lawful retention/anonymization on account deletion. Do not infer
earlier choices from current flags or invent historical versions. Add tests for
evidence fidelity, rollback and ownership before declaring this gate closed.

Required sign-off record: reviewer, role, approved copy/version, patient groups,
jurisdictions, legal basis, clinical rationale, date and outstanding conditions.
Do not pre-check any optional toggle. Legal/clinical approvers have not signed off.

Reference: [EDPB lawful processing and consent guidance](https://www.edpb.europa.eu/sme/be-compliant/process-personal-data-lawfully_en).
