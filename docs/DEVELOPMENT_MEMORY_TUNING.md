# Development memory tuning

Use only project `33f9a938-49e3-42c5-83c7-633ee85760fa`, environment
`56b85cc3-e80b-4cb9-a0d4-b47abe8586f6`. Production settings are not approved by
this procedure; use development evidence for a separate production proposal.

## API idle workers

`DEVELOPMENT_FPM_ONDEMAND=false` preserves the existing dynamic PHP-FPM pool.
Setting it to `true` requires both exact Railway IDs and
`APP_URL=https://api-dev.cozyhealth.org`. Other identities or invalid flag values
refuse API startup. The worker-only entrypoint does not start or configure FPM.

The development override selects `pm=ondemand` with a ten-second idle timeout.
The base pool retains `pm.max_children=10`, `pm.max_requests=500`, timeouts and
logging. No public status endpoint, PHP memory-limit change or OPcache change is
introduced. Disabling the flag removes only this generated override on startup.

Build validation runs `tests/Support/verify_development_fpm.sh` in the exact
runtime image: default/dynamic and opted-in/on-demand parsing, inherited
concurrency/recycling, stale-override removal and rejection of unsafe identities
and values. The `configuration-only` mode is the focused shell subset for hosts
without PHP-FPM; hosted CI always runs the full parser checks.

## Development MySQL

The approved initial target is a 256 MiB InnoDB buffer pool and one instance,
replacing the recorded 1 GiB/four-instance layout. Preserve all other startup,
TLS, durability, volume, key and backup settings. Change only the development
service's persisted command; do not edit shared production service settings.

Before restarting, retain the exact old command and verify a recent recovery
copy, shared maintenance and drained requests/jobs. Pause schedulers and workers,
apply the new command, verify MySQL/TLS/settings, then reopen deliberately.
Do not restore the database volume as a configuration rollback. Restore the old
command if startup/connectivity or reproducible performance checks fail.

## Acceptance and rollback

Compare identical pre/post warm, idle-first and bounded concurrent requests.
Warm p95 must stay within the larger of 20 percent or 25 ms above baseline;
idle-first latency within 200 ms. No failed probe, timeout or new OOM is accepted.
Verify a post-change encrypted backup and preserve daily scheduling/receipt
coverage. Disable the API flag if it regresses or provides no useful saving.

Targets are 20 percent lower MySQL average memory and 10 percent lower API
quiet-period memory under comparable traffic. Immediate measurements are
provisional; comparable 24-hour memory, CPU, errors and latency are required for
cost acceptance. Report pending measurements accurately without continuous
polling or promising automatic notifications. Do not infer a bill reduction from
configured limits alone.
