# Provider security checklist and incident extension

2026-10-09. Technical review, not a compliance certification or penetration test.
Owner: engineering/security lead. PD-6 release work is local; launch gates below
require named owners and signed closure evidence.

## Verified evidence

| Control | Evidence / scope | Result |
| --- | --- | --- |
| Backend authentication/isolation | ProviderAuthTest; patient/provider token separation; disabled accounts | Local suite passes |
| Login/reset rate limits | routes/api.php; auth test rejects excessive attempts | Local suite passes; bridge IP quota is shared |
| Bridge cookie | AES-256-GCM; production `__Host-`, HttpOnly, Secure, Strict, path `/`; no Domain | Code review; actual successful deployed login not tested |
| CSRF | Exact Origin and explicit request header for mutations | Unit/integration pass; deployed missing-Origin returns 403 |
| Credentials and lifetime | Server-only token; cookie/token both expire in 30 minutes | Backend/unit/integration pass; active-use timeout documented |
| Route and payload isolation | Explicit method/path/query allowlist; JSON; 64 KiB; TLS config | Unit/integration pass |
| Patient permissions | Active verified link; separate default-off categories; immediate backend recheck | Client/note/consent tests pass |
| Report authorization | Provider ownership; link/consent snapshot; signed route plus bearer auth | Report tests pass, including revoked and cross-provider access |
| PDF forwarding | Same API origin and expected report path; no signed URL/token exposed | Integration pass |
| Audit and access log | Link/patient attribution on reads/writes/downloads; patient-only access history | Note/audit/resource/report tests pass; consent proof gap below |
| Privacy responses | Private/no-store bridge and PDF responses; no invented clinical risk score | Code/integration review |
| Pruning | Preview; approved-policy gate; holds; evidence exclusions; scoped orphan cleanup; lock/batches | Eight new tests, 45 assertions |
| Production configuration | Exact origin, HTTPS API, correctly shaped secret; key/mail present; debug false | Private Railway metadata check, no values disclosed |
| Production availability | Exact PD-5 SHAs SUCCESS, health/login 200, unauthenticated 401, configured-origin 422 | Anonymous deployed smoke only |

Final local backend suite: **415 tests, 2,500 assertions**. Dashboard PD-5 checks:
lint/TypeScript/build pass; **4 session tests** and HTTP integration pass. Browser
checks were skipped on this rerun; earlier PD-5 browser evidence is historical.
These counts do not include a new real-provider production end-to-end pass.

## Open launch gates

| Gate | Finding / action | Owner |
| --- | --- | --- |
| Legal and clinical sign-off | All legal/privacy/processing/consent/retention drafts unapproved | Product + counsel + advisor |
| Consent evidence | Current audit does not persist category/value/wording version; deletion cascades can remove/null proof | Engineering + counsel |
| Provider terms acceptance | No versioned acceptance flow/record | Product + engineering |
| Staging | Separate provider dashboard/API test environment absent | Operations |
| Registration and verification | API registration public; active status is enforced, provider `is_verified` is not a general access gate; approve and implement pilot policy | Product + engineering |
| Linking code delivery | Generated code is not delivered; mobile “sent” wording remains inaccurate | Engineering |
| PDF durability | API deployment has no report-storage volume; disk local uses `storage/app/private` | Operations |
| Deletion/retention | Approve clinical and consent lifecycle, backups, account-deletion proof and holds; manual orphan cleanup not yet deployed/run | Counsel + engineering + operations |
| Hosting environment label | Railway production API has `APP_ENV=staging`, debug false; determine whether intentional before changing operational behavior | Operations |
| Branded domain | `providers.cozyhealth.org` DNS/TLS not configured/verified here | Operations |
| Pilot | Three approved providers and authorized patient flows not onboarded/tested | Product |
| Device | Full iPhone provider/consent/report flow remains deferred to end of PD-7 | Product/QA |
| Security operations | Named incident/privacy contacts, restore drills and alert delivery unverified | Operations/security |

MFA, account-aware/distributed login throttling, dependency advisory remediation,
full penetration testing and browser security-header review remain security-track
work; counsel/security must decide which are prerequisite for the pilot. Existing
Composer advisories remain tracked for Phase 12; this phase did not update packages.
Backend CORS explicitly allows credentials/specific origins but omits PATCH. The
same-origin bridge does not need backend cookie CORS; direct-browser CORS repair
remains the existing deferred task. Forwarded browser IP is not trusted; the
bridge's network IP is what backend audit/rate limits see.

## Provider incident-response extension

1. Route reports to [named security lead + backup + privacy/counsel contacts].
   Record discovery time in UTC, affected services/accounts/data categories,
   jurisdictions, evidence location and incident owner. Establish these contacts
   before pilot; no actual notifications were sent during PD-6.
2. Contain by disabling affected providers/revoking bearer credentials, isolating
   compromised services and rotating keys where needed. Rotating the dashboard
   secret invalidates cookies, not underlying bearer tokens. Stop pruning and
   enable a global legal hold; preserve relevant access logs, consent/link events,
   reports, deployment SHAs and storage/database evidence with restricted custody.
   Avoid exporting patient content into tickets or normal application logs.
3. Determine affected individuals/practices and exposed categories, including
   generated/downloaded PDFs and vendor copies. Audit issuance and file downloads
   separately. Investigate time-window gaps and bridge-IP limitations. Document
   whether confidentiality/integrity/availability was affected and the mitigation.
4. Counsel assesses applicable notification duties and contracts immediately.
   Under GDPR, controller authority notification is generally within 72 hours of
   awareness where required; processors notify controllers without undue delay.
   HIPAA notifications follow role-specific duties, without unreasonable delay
   and applicable outer limits; contracts may require earlier escalation. Do not
   treat the maximum period as permission to wait. Assess Nigeria and other local
   law separately. Record the legal basis for notifying or not notifying.
5. Use approved communications to practices, affected patients, authorities and
   vendors through authorized contacts. State known facts, affected categories,
   mitigation/help steps and contact details; track receipt and updates. Only
   authorized staff send notifications after counsel's determination.
6. Recover from trusted images/backups, reconcile deleted records and current
   revocations before reopening access, verify all authorization checks, and obtain
   incident-owner approval. Complete a timeline, root cause, corrective actions,
   notification record and a restore/tabletop drill. Do not destroy held evidence
   when closing the incident without the hold owner's release.

Sources: [HHS breach guidance](https://www.hhs.gov/hipaa/for-professionals/breach-notification/index.html),
[GDPR Articles 28, 33 and 34](https://eur-lex.europa.eu/eli/reg/2016/679/oj/eng),
[Nigeria Data Protection Act](https://ndpc.gov.ng/download/nigeria-data-protection-act-2023).

## Release decision

Technical deliverables are available for review. **External launch is not
approved.** Close the launch gates with evidence and named sign-off; do not mark
legal approval, clinical review, staging, pilot or full device verification passed
from local automated tests alone. PD-7 has not started.
