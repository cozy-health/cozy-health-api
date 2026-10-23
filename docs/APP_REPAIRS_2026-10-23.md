# Development app repairs ? 23 October 2026

## Approved behavior

The intended store audience is 13+. Store ratings and download controls replace Cozy's custom age-entry and guardian approval onboarding. No Apple/Google in-app age API is added. Store questionnaires must describe actual content; store account configuration and releases remain pending. This is a product direction, not a verified age result for each account.

Ordinary terms, privacy and health-data consent remain. Retired age/guardian writes return 410 without consuming links or sending mail; older eligibility reads return a nonblocking compatibility response. Historical records and manual retention tooling are preserved; scheduled guardian cleanup is retired. Existing account and provider authorization remain.

Community replies and post likes create account-owned, generic notices in the shared Home/Community inbox. Self-events and blocked users are excluded; repeat likes do not create duplicates. Pending replies create no notice until moderator publication. Anonymous identity and post/comment text are absent from notification copy. Settings expose replies, likes and Community phone alerts. Push uses existing development configuration and permission gates, current consent/device ownership, quiet hours and delivery limits. Explicit transient failures can retry; ambiguous sends do not replay. The inbox remains authoritative when phone delivery is unavailable.

The journal editor clears unfinished text on account/session changes; journal/profile saves guard the handoff to account-scoped sync. Voice close/discard asks before discarding unsaved audio and failed saves retain the recording. Reminder days wrap below their label, and the consent urgent-support action uses readable theme foreground ink.

Earlier repairs in this batch include consent loading/error recovery and server enforcement, published article browsing plus admin draft/publish/unpublish, visible breathing timers, microphone Settings recovery, invitation share/copy, package details with billing held, and back-navigation fallbacks on the changed flows.

## Validation and delivery

Final command logs and the complete evidence record are in the parent workspace output/store-age-* and docs/APP_REPAIR_IMPLEMENTATION_2026-10-23.md. Validate exact committed sources and remote develop CI before readiness. Physical Android/iPhone acceptance remains outstanding; local widget tests do not certify native permission/push behavior. No production work or store release is authorized. Billing remains held.

## Repository validation

Local final API suite: 702 passed, 4,967 assertions (PHP 8.2 with GD enabled in a command-scoped scan directory). SQLite isolation only; hosted MySQL checks remain required. Two additive migrations introduce article drafts and Community notification delivery records.
