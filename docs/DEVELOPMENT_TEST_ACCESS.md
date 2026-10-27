# Temporary development plan access

This is an administrative test facility, not a purchase, student approval or production entitlement. It does not implement the remaining plan-specific feature gates.

Enable `DEVELOPMENT_TEST_ACCESS_ENABLED=true` only on the approved Railway development API/worker. The runtime additionally requires project `33f9a938-49e3-42c5-83c7-633ee85760fa`, environment `56b85cc3-e80b-4cb9-a0d4-b47abe8586f6` and HTTPS `APP_URL=https://api-dev.cozyhealth.org`. Other environments return 404 and ignore existing grants. Default is disabled. Apply migrations before activation.

An admin with subscription-management permission can use `/admin/manage/development-access` on dashboard-dev. Select an existing active consumer email, Student/Standard/Premium, 1–30 days (default 7), and a reason without private health information. Review and confirm. No account is automatically granted access by deployment.

GET/POST `/api/v1/admin/development-access` and DELETE `/api/v1/admin/development-access/{uuid}` use the existing scoped admin token. Grant history is paginated. Reasons are encrypted and excluded from responses. Grants and revocations are audited. Concurrent grants lock the account; an active grant must be explicitly revoked before replacement. Repeated revocation is safe.

The current subscription endpoint overlays an active grant, labelled `source=development_test` and with an ISO-8601 expiry. At expiry/revocation it returns the unchanged genuine subscription or existing no-subscription response. No store receipt, payment, student application or approval is created. The app replaces its current-status cache and labels test access on its subscription screen. Expiry is enforced on the server and in the cached status; revocation reaches offline clients only when they next refresh successfully. Do not treat this as an offline revocation guarantee.

To disable new and existing test access, turn off the flag in the same development services. Keep grant/audit history. Never copy this flag or grants into production. Store billing remains disabled/on hold.
