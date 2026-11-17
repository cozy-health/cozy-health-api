# Development app association endpoints

E02 reconciliation, 6 November 2026. Development only.

Both `/.well-known/assetlinks.json` and `/.well-known/apple-app-site-association` returned HTTP 500 in the authorized Railway development environment. The browser `web` middleware attempted database-session access, but this token-authenticated API has no `sessions` table. Three isolated regressions with the database session driver reproduced the failure.

The endpoints now use the stateless API middleware, as the existing public support-inbox route does. Response contracts are unchanged: configured identities produce the existing JSON; absent signing fingerprints/application ID produce HTTP 503. Responses do not start browser sessions or set session cookies. Focused result: three passed tests, 12 assertions. Full local suite passed: 1,006 tests / 11,816 assertions, using the existing process-only GD/memory configuration. Local totals include preserved uncommitted assessment tests; hosted counts may differ. MySQL validation remains hosted CI.

At inspection, development had neither Android association fingerprints nor an iOS application ID configured. Android's persistent signing identity awaits the owner's decision; iOS association depends on the actual Apple signing/provisioning route. Do not invent these identities or copy production settings. A missing identity is an open configuration dependency, not successful OS link acceptance.

The mobile companion change registers development invitations at `api-dev.cozyhealth.org` rather than the legacy Railway hostname. Real Android verified-link/iPhone associated-domain acceptance remains a separate device check. No API database migration, credential change, external message, production operation or retention activation is part of this repair.

Workspace evidence: `output/e02-api-inspect.json`, `output/e02-web-inspect.json`, `output/e02-associations-before.log`, `output/e02-associations-after.log`. Delivery commit, final suite/CI results and deployment status belong in the workspace handoff.
