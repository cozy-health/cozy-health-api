# Community development testing

This activation is for Railway project `33f9a938-49e3-42c5-83c7-633ee85760fa`, environment `56b85cc3-e80b-4cb9-a0d4-b47abe8586f6`, API `https://api-dev.cozyhealth.org` only. Production launch readiness and production feature flags are unchanged.

## Configuration

On both development API and queue-worker services set `COMMUNITY_DEVELOPMENT_TESTING_ENABLED=true` and `COMMUNITY_GUIDELINES_VERSION` to the currently published `ugc_guidelines` version returned by the development configuration. The platform-provided `RAILWAY_PROJECT_ID` and `RAILWAY_ENVIRONMENT_ID` and `APP_URL=https://api-dev.cozyhealth.org` must match. Keep `COMMUNITY_RELEASE_READY` unchanged.

`CommunityAvailability` is shared by the patient middleware, advertised configuration and Community push worker. The effective Community flag requires a posting-guidelines version, the persisted feature switch, and either existing public readiness or the explicitly guarded development exception. A development switch alone cannot enable another environment, a production API hostname or a malformed URL. There is no new public endpoint, schema migration or client API change.

After deploying the reviewed `develop` commit, run `php artisan community:development` to preview, then `php artisan community:development --apply --fixtures --verify` on the explicitly selected development API service. Preview changes nothing. Apply enables the development database flag, invalidates only its app-config cache, and optionally provisions labelled fixtures. Setup is idempotent and refuses to take over conflicting identities/groups. On a verification failure it reports failure without pretending to roll back an already activated feature.

## Fixtures and verification

The group `[Development test] Community playground` and accounts `community-dev-author@example.test`, `community-dev-reader@example.test`, and `community-dev-moderator@example.test` are synthetic. Their passwords are random and undisclosed; they are not shared interactive login credentials. No signup emails are sent. Use normal development accounts for manual app/dashboard review and join the labelled group.

Live verification creates a clearly labelled synthetic post and comments and uses short-lived credentials that are removed in `finally`. It verifies authenticated groups, joining/leaving, post retrieval, anonymous replies, idempotent likes, shared inbox/unread/read state, cross-account isolation, reply preferences, reports, blocking and moderator removal/republication. Its synthetic spam report is dismissed so it does not leave a pending moderation task. Existing users/content are not modified. Only the labelled test records are touched; no private health text is needed.

Fixture phone delivery is disabled and verification refuses accounts with phone consent or registered devices. Existing development `PUSH_ENABLED`/`PUSH_IOS_ENABLED` settings are not enabled by this operation. Actual phone-push acceptance requires designated devices and valid configuration; code and inbox verification are not proof of delivery.

Published legal documents remain immutable. Inspection found the development guideline entry was an empty, unpublished draft, so activation stopped before any variables changed. The owner must select the clearly labelled development-test rules or the existing version 1.0 for development publication; unrelated terms/privacy/health-data documents and production publications remain unchanged. Obsolete guardian wording in version 1.0 needs a separate policy revision before production release. The intended store audience stays 13+.

## Checks and rollback

Run the focused Community development/notification/API tests, the complete PHP suite with required extensions, and every applicable hosted CI job for the exact pushed commit. Verify `/api/v1/config` advertises `flags.community=true` on development and that the worker has the same guarded configuration. Native app/dashboard and physical-device acceptance should be reported separately.

To stop development testing, turn off the development Community feature switch or set `COMMUNITY_DEVELOPMENT_TESTING_ENABLED=false` on both development services and refresh their configuration. Preserve fixture records and the previous configuration snapshot. Never change a production flag or a public readiness approval as cleanup.
