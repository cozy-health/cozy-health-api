# Development monitoring

Sentry captures filtered API/worker exceptions. Individual errors are reviewed in
the Sentry dashboard; the development-project email rules are disabled. PagerDuty
is reserved for the separate external uptime check, not every exception.

Activation requires `SENTRY_DEVELOPMENT_ENABLED=true`, the API development DSN,
`APP_ENV=development`, `APP_URL=https://api-dev.cozyhealth.org`, Railway project
`33f9a938-49e3-42c5-83c7-633ee85760fa` and environment
`56b85cc3-e80b-4cb9-a0d4-b47abe8586f6`. Explicit local runs are supported without
a Railway environment identity. Production, staging and ordinary tests stay off.
The DSN validator accepts only API development project `4512362654990416`.

`SENTRY_RELEASE=cozy-health-api@<40-character commit SHA>` is optional. The privacy
filter retains only this validated release, a fixed component/environment/server
identity, safe exception types and application-source file/line information.
Requests, identities, messages, SQL, job payloads, variables and breadcrumbs are
not copied. Tracing, profiling and log uploads are disabled. Sentry project-side
IP scrubbing must also remain enabled.

For a labelled controlled fault, run `php artisan monitoring:development-failure`
only against the verified development service. Its exit code 1 is expected, but
does not prove ingestion: confirm the event in Sentry. TLS verification stays on;
a local certificate trust failure must be repaired rather than bypassed.

The external uptime target is `https://api-dev.cozyhealth.org/up`, using GET and
the case-sensitive keyword `"status":"ok"`. It checks availability/dependency
health, not every user journey. Its PagerDuty destination is **Cozy Health
Development**, with immediate push and delayed unacknowledged email fallback.
Do not use CozyMail forwarding or deploy a custom monitoring worker.

Current live acceptance, exact commits and any pending account/device checks are
recorded in the workspace `docs/HANDOFF.md` and monitoring evidence file. Never
infer production activation or automatic recovery from local tests.
