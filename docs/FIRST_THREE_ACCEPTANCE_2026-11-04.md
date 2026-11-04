# First three workstreams: development acceptance

4 November 2026. Owner authorized implementation of development delivery, API contract reconciliation and sign-in/registration acceptance. Provider priority/insights remains planning only. No production work or rollout-flag activation is authorized by this batch.

## Delivered baseline

API `1815e42521c196bab3908b935ce5399fe6e9c7e9` was pushed to develop after obtaining the missing GitHub workflow scope. [Exact release CI](https://github.com/cozy-health/cozy-health-api/actions/runs/41878507032) passed. Development API and worker both report that SHA, the required support/privacy tables and a fresh `ops:scheduler:last-tick` cache heartbeat. The existing queue probe received a worker acknowledgement at 2026-11-04T19:26:19Z. Sign-in enrollment and consent enforcement remain off.

Mobile develop `fced96efe820962d70942faeb1ce33c5f9f3474c`: [Android](https://github.com/cozy-health/cozy-health-mobile-app-main/actions/runs/41853012720) passed; [iOS attempt 2](https://github.com/cozy-health/cozy-health-mobile-app-main/actions/runs/41853012718) passed after the initial hosted-runner acquisition failure. Physical-phone acceptance is separate.

## Contract reconciliation

OpenAPI and Postman now cover all 320 application operations. The two exact exclusions are Swagger's HTML documentation and OAuth callback pages; no business operation is excluded. See [coverage ledger](API_OPERATION_COVERAGE.json) and [exclusions](API_OPERATION_EXCLUSIONS.json). Role authentication, source references, validators, pagination and error metadata are recorded; 15 retired operations retain their 410 disposition. Dynamic response shapes are deliberately conservative, not a claim of complete business-journey acceptance.

Five stale Postman requests were corrected, twelve unsupported prototype requests archived with reasons, and missing operations added. Auth examples use fixture variables, with pending-challenge and session credentials distinguished. Do not bulk-run mutations against shared data. Unused mobile settings helpers have no tracked callers; active profile code uses the current contract, so no unrelated mobile refactor was made.

Validation: focused contract regression 5 passed / 3,739 assertions; complete API 976 passed / 10,940 assertions; isolated MySQL release selection 273 passed / 5,939 assertions; Student and customer-operations concurrency probes passed. Sign-in focused baseline: 115 passed / 778 assertions. These checks include refresh rotation/replay, registration rollback, challenge expiry/roles, queued reset mail and paused enrollment.

## Sign-in acceptance still being closed

Dashboard provider protection and admin HTTP/browser acceptance passed with synthetic local fixtures. Dashboard strict typecheck, 55 unit tests and development-configured optimized build passed. Provider registration passed, including invitation acknowledgements, changed consent, replay rejection, theme checks and mobile layout. No auth runtime defect has been established and no auth runtime logic was changed.

Live email verification/reset and inbox receipt remain pending. The dated owned aliases are outside the current development mail allowlist; no aliases, live accounts or mail configuration have been changed. Temporary allowlisting and owner inbox-code participation were requested. Local queued-mail assertions and synthetic browser checks do not establish live email delivery.

Detailed logs are workspace-local under `output/approved-*`; unrelated repository edits are preserved. Contract changes and dashboard fixture repairs are not yet committed at this checkpoint. The task-owned isolated MySQL container and its anonymous volume were removed after verifying the exact ID and task label. Next: record live email disposition, then verify scoped develop commits and exact remote CI/deployment evidence.
