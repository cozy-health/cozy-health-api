# Provider legal review packet

Draft v0.1, 2026-10-09. Owner: product lead; reviewers: counsel and clinical advisor.
These documents are unapproved drafts for review, not executed agreements or a
claim of HIPAA, GDPR or Nigerian data-protection compliance. Do not onboard
external providers until the launch gates in SECURITY_CHECKLIST.md are closed.

## Role and applicability worksheet

For each pilot, counsel must record jurisdictions, patient ages, practice legal
entity, clinical services, transactions, controller/processor roles, lawful basis
and the applicable health-data condition. A professional license alone does not
establish HIPAA covered-entity status. Determine whether Cozy Health processes PHI
on behalf of a covered entity and whether a BAA is required. Assess GDPR territorial
scope, special-category processing, DPIA and international transfers separately.
Assess Nigeria's Data Protection Act where applicable; location of the developer
does not determine every patient's jurisdiction.

| Decision | Required evidence / reviewer | Status |
| --- | --- | --- |
| Legal entities, governing law, privacy/security contacts | Counsel; replace all bracketed fields | Pending |
| HIPAA covered entity / business associate status | Practice-specific assessment; agreements with applicable vendors | Pending |
| GDPR controller/processor roles; Articles 6/9 conditions | Data-flow assessment and purpose-by-purpose legal basis | Pending |
| Nigerian applicability, registration, transfers | Counsel's local assessment | Pending |
| Hosting regions, vendors and subprocessors | Operations inventory, vendor contracts, access controls | Pending |
| BAA / DPA and transfer annexes | Signed applicable agreements before regulated access | Pending |
| Patient notice, minors and consent evidence | Counsel + clinical review of CONSENT_REVIEW.md | Pending |
| Retention, deletion, legal holds and backups | Approved RETENTION.md schedule | Pending |
| Breach and rights-request ownership / deadlines | Named contacts and tested escalation | Pending |

Engineering facts: independent sharing toggles default off, full journal bodies
are excluded, authorization is checked on each API request, and downloaded copies
cannot be recalled. Provider data is not used for model training by this provider
feature. Do not infer broader product practices from this statement.

## Review process

Return annotated drafts and a signed decision record with reviewer, version,
date, jurisdictions, approved retention schedule and remaining conditions. Resolve
the evidence gaps before acceptance is captured in the product. Publish approved
versions and persist acceptance/version proof before pilot onboarding. No provider
terms acceptance flow currently exists.

## Sources checked on 2026-10-09

- [HHS business associate guidance](https://www.hhs.gov/hipaa/for-professionals/privacy/guidance/business-associates/index.html)
- [HHS sample BAA provisions](https://www.hhs.gov/hipaa/for-professionals/covered-entities/sample-business-associate-agreement-provisions/index.html)
- [EDPB lawful processing guide](https://www.edpb.europa.eu/sme/be-compliant/process-personal-data-lawfully_en)
- [GDPR official text](https://eur-lex.europa.eu/eli/reg/2016/679/oj/eng)
- [Nigeria Data Protection Act official download](https://ndpc.gov.ng/download/nigeria-data-protection-act-2023)

The templates paraphrase the required topics rather than reproducing official
sample agreements. Counsel must adapt them to the actual parties and services.
