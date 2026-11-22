# Resumed production recovery and journeys — 22 November 2026

Owner re-enabled Railway and authorized online checks. No application deployment/store publication, application resource-limit change or desktop control. API/worker report source 205c61c68db544640d140b2715805b7d74916c64. Dashboard Railway metadata reports4aef32ddc69a1d75e2f64e3c0bf63f649580185f. Exact object fetched read-only; app/components/lib, package/lockfile, Next configuration and tsconfig compare unchanged against tested623f6a2. No checkout switch or deployment. Database MySQL9.7.4,71 applied migrations/zero pending, encrypted session, private object storage; API healthy and maintenance off.

## Passed live acceptance

- Controlled patient sessions renewed; patient denied admin support/privacy/provider role access. Existing web-provider message is readable by its patient.
- Suppressed support email: owner supplied a fresh inbox code, account verified, owner retried the existing notification, server reply count stayed one, owner confirmed actual Gmail receipt with screenshot. First code attempt was rejected after the15-minute lifetime elapsed; fresh request/attempt succeeded. No clipboard was read; owner-provided codes were used in memory, not written to evidence.
- Original survivor ticket: owner sent a labelled fresh reply and resolved it; server observation and owner-confirmed inbox arrival recorded. This passes current delivery/reply behavior. Historical nonreceipt remains unexplained; SMTP/provider acceptance was not substituted for inbox proof.
- Provider permissions: before withdrawal owner confirmed records/messages open; all four sharing flags changed through existing patient API, read back false, owner saw records hidden/locked. Exact test link then revoked through existing route; owner saw no patients. Direct old provider endpoint/URL attempts were not separately observed. Two additional provider pilots remain open.
- Cloud export redownload: checksum/private headers/owned identity, mood, journal, assessment and audio integrity passed; unrelated account denied and excluded. ZIP28,985bytes, SHA-2566088e3a670cf50cda519f4a008004785b067e08039cb09138112738446fcaaf6. This is the cloud ZIP, not device JSON/provider PDF.
- Owner approved privacy deletion of disposable account through admin UI. Account and tokens removed, two admin audit entries and completed independent object-storage receipt verified. Entire cleanup queue matched only its two approved files before invoking existing avatars:cleanup; both objects and cleanup queue removed. Survivor retained. Owner confirmed deletion email and supplied screenshot.

## Expanded recovery measurement

Fresh backup ec7a7069-b529-44ac-98d9-56578dfd4d9b, snapshot14:15:28UTC; all118 table digests/counts and five private files; independent verify passed. Approved bounded maintenance pause4.727seconds, then maintenance off/queues healthy. Historical expanded198ca1af retained; its encrypted archive separately retrieved as untimed preparation.

Fresh clock14:23:37.032UTC to14:24:55.174UTC. RPO489.032seconds≤86,400; RTO78.142seconds≤3,600. Includes retrieval, independent-key access, restore, replay, restart and validation. Independent key is the previously validated Bitwarden recovery copy retained under CurrentUser DPAPI; no production APP_KEY lookup. Fresh owner vault access/offline export decryption was not measured here.

Restore matched118 database digests/counts and five file checksums, retained maintenance before replay, quarantined recovered deliveries, replayed completed deletion receipts, removed deleted account/tokens/files while preserving survivor data/files, and repeated replay without change. Internal worker, MySQL restart,200 health/401 unauthenticated/deleted session and200 freshly authenticated survivor passed. New target used an internal-only Docker network and MySQL9.7.4. Container25f97493a5459bdf14d72edb6ef552451b114e35e0584a86fc97cc3102f3ed7a stopped after success; original rehearsal/production were not overwritten. Post-drill production health remains OK. No restored outbound delivery was executed.

## Mail setup repair

Brevo showed verified sender addresses but unauthenticated cozyhealth.org and default signing; messages appeared from brevosend.com while app configuration was already noreply@cozyhealth.org. Owner used existing-domain automatic authentication. New screenshot shows Authenticated; one labelled message sent through existing branded mail/production SMTP arrived from noreply@cozyhealth.org, confirmed by owner and screenshot. Optional link branding remains separate. Brevo primary guidance: https://help.brevo.com/hc/en-us/articles/16045394674066-Troubleshooting-Domain-authentication . No app sender/address change or new parallel setup.

## Open requirements

- Today’s03:00 Lagos archive371c0c86 and two older backups pass integrity; latest is within24h. Scheduler invocation/UUID correlation remains open because two bounded log queries returned no lines. Actual seven-day elapsed retention cannot close from simulation or archive configuration.
- Apple API-host app association503 remains a production identity/setup dependency. Android association serves org.cozyhealth.app and three certificates; no signed-device install/link/push acceptance inferred. Unconfigured website-host404 responses do not establish a defect.
- Owner now allows US$3 additional load-test spend using existing service limits. Preflight reads metrics and24CPU/24GB maxima per API/worker/MySQL. The 300-user hosted run is now in progress after isolation, suppression, generator headroom and cost checks passed; no application resource-limit change. No full capacity pass is claimed before measured completion. Railway hard limit18 unchanged.
- Remaining device/store/billing, two additional providers, uncovered monitoring/operating/legal gates and independent offline vault-export recovery remain in BUILD_PLAN.md. No entire launch workstream is marked complete.

Detailed private/review evidence stays in workspace output/online-resume-20261122/ and the existing recovery-journeys fixture history. Screenshots were hashed and not committed. No secret/code/customer content is in this report.

Local generator preflight:15.82GB total/2.08GB free/86.83% memory used before load. Owner chose remote Railway generation to avoid stressing the PC. First Postman loopback invocation is not a pass: the temporary server closed before runner completion, causing connection failures. That initial local attempt made no production requests. Subsequent hosted preparation created exactly300 guarded .invalid synthetic accounts; see the current capacity checkpoint below. CLI1.93.0 was installed from the pinned official npm package; local collections run without recording/upload. Evidence remains local.


## Hosted capacity checkpoint (historical preparation; stopped result below)

Owner requested remote generation and approved US$3 additional spend. Temporary service66bf0588-4751-4cea-a6cf-8abf7c3422e3 has one replica,1CPU/1GB, no public domain/volume and NEVER restart; only synthetic account capabilities, no database/platform credentials. Pinned grafana/k6:2.4.0 image digest d41bb79cd8de5a7ad619b53570e3712633e8bff0937010a5c6c57a4747e7a276. Generator-only300VU self-test and ten-operation production contract smoke passed; initial packaging/fixture and structured-log errors remain in evidence.

First full attempt33b25b78-92a5-4832-9f3a-16a181716618 stopped after local observation transport failure, before any valid sample; stop confirmed. It is not a capacity pass. Observer now makes bounded idempotent read retries and retains gaps; unavailable required observations stop the dependent run. Test thresholds were not weakened.

Retry146b858a-5bde-4ab0-bc23-ab159947d94c began19:05UTC. Exact source/fixture hashes and conservative cost boundUS$2.948185 (including the prior observed workspace increase) are in local evidence.43-minute generator hard wall includes30-minute300VU sustained traffic and10-minute recovery; initial calibration is gradual. Existing app limits/source unchanged.300 regular synthetic .invalid users have invalid-mail flags, disabled notifications, no push registrations/provider links and temporary synthetic Premium entitlements, not real purchases. Current consent-blocking is disabled and documents are drafts; no false human consent was recorded and the legal launch gate remains open. Aggregate analytics can include synthetic workload. Exact account teardown is prepared through the existing deletion workflow; no customer deletion is authorized.

Full latency/error/resource/queue results, recovery and teardown remain pending. Local source/fixtures and private reports stay under output/online-resume-20261122/; no credentials are committed.

## Hosted capacity attempt stopped safely — 22 November 2026

The generator ran on Railway, protecting the PC from300-user load. Capacity remains **open/incomplete**, not failed app capacity and not passed acceptance. Retry deployment146b858a-5bde-4ab0-bc23-ab159947d94c ran19:05:19–19:15:29UTC, then the required local observer timed out. Stop accepted and deploymentStopped true confirmed. The observer did not record which subcall timed out; do not invent a root cause. First attempt's separate transport failure remains preserved. No third full run started.

- Nine valid observations19:05–19:13UTC: production healthy/maintenance off; failed jobs0/oldest queue age0. Peaks in CPU cores: API2.1074/24, worker0.00122/24, MySQL0.39824/24, generator0.06640/1. Peak RAMGB: API0.27182, worker0.08074, MySQL0.54023, generator0.21617. These short-run samples do not establish30-minute capacity or worst instantaneous peaks.
- No completed full latency/error summary,30-minute sustained period or ten-minute recovery acceptance. Generator self-test and ten-operation smoke remain separate measured passes; they do not replace capacity acceptance.
- Shared AccountDeletion cleanup completed for the exact300 reserved .invalid fixtures:4,030 moods/2,016 journals/2,012 assessment attempts removed; remaining accounts/tokens/records/subscriptions all0. Maintenance off/jobs0/failed jobs0 afterwards. Invalid-mail flags suppressed deletion emails; no real recipient deliveries. Existing survivor/admin/provider accounts are outside this teardown. Aggregate analytic events can still include labelled test activity; no broad deletion of audit/analytics/backups.
- Runner disarmed and capability JSON cleared with no redeployment, read-back verified; exact temporary service66bf0588-4751-4cea-a6cf-8abf7c3422e3 deleted afterwards. No continuing generator spend/load. Application source/limits unchanged.
- Reported workspace billUS$0.767182 versus initialUS$0.619699: observed differenceUS$0.147483, includes normal activity and may lag. This is not an exact attributed test bill. ApprovedUS$3 test cap/workspace hard18 unchanged.

Next: validate an observer independent of the PC connection, then recompute a conservative remaining-run cost including previous attempts before starting it. Preserve all targets and failure evidence. No full launch or capacity checkbox closed.

Evidence: output/online-resume-20261122/capacity-incomplete-closeout.json, capacity-watch-result.json, capacity-observations.jsonl, capacity-retry-stop-confirmation.json, capacity-cleanup-result.json, capacity-runner-disarmed.json, capacity-temporary-service-delete.json; prior attempt under capacity-attempt-1/.
