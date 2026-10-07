# Deferred work

## Deferred from Phase 2.1 (Database Optimization)

| Item | Why | When |
|---|---|---|
| Quiz index plan optimization | MySQL chose the older composite index for the quiz_attempts(user_id, completed_at) query with test data; the plan may change with real data distribution | Phase 19 (Quiz System) — revisit after real quiz data exists |
| Subscription index EXPLAIN verification | MySQL preferred the existing unique user_id index; monitor query performance before reconsidering index selection | Monitor post-launch; revisit if slow queries surface |
| `journal_entries.is_draft` column + index | Column does not exist; needed only if the draft-resume feature uses it | If draft-resume for journal is added |

The subscription query used `type: const` on the unique `user_id` index.
Because each user has at most one subscription-status row, this is an efficient
plan; growth alone does not imply that the composite index should replace it.

## Deferred from Phase 2.2 (N+1 Audit)

| Item | Why | When |
|---|---|---|
| Chat message-list relationship audit | ChatController::show returns only conversation attributes; no message-list route or messages relationship is implemented | When conversation messages are implemented |
| Community and content-article relationship audit | CommunityPostController and ContentArticleController do not exist | When those feature endpoints are implemented |
| Saved-article HTTP relationship audit | SavedArticleController does not exist; ContentController::index reads saved_articles but has no registered route. Its direct invocation has no N+1 | When the saved-article route is implemented |

## Deferred from Phase 2.3 (Column Selection)

| Item | Reason | When |
|---|---|---|
| SELECT * removal on list endpoints | Mobile cache replaces records; partial list responses would wipe cached data | After mobile preserves absent fields during partial updates, supports detail fetching for incomplete records, and the compatible mobile release is shipped and verified on device |
| Journal list body field | Full body is required for the cached detail view | After mobile supports separate summary/detail fetch |
| Profile, notification, and subscription projections | All three responses replace cached records; the revised scope permits trimming only non-cached responses | After the mobile cache contract is updated and verified |
| Provider column selection | No persistent cache, but mobile expects has_provider and connection status/provider_name/provider_phone while GET /provider returns a connection with a nested provider; SELECT-only changes cannot resolve this mismatch | After the provider response contract is reconciled and tested |
| P0 mobile cache merge (Phase 2.5) | Typed fromJson constructors already replace absent fields with defaults/nulls before LocalDbService receives records, so storage cannot distinguish absence from an explicit null | Next mobile session; retain raw JSON key presence through ingestion, preserve absent values, and honor explicit nulls |

Merge alone does not hydrate a previously uncached record from a partial list.
Before removing full bodies or notes, mobile also needs summary/detail fetching
and a way to identify incomplete cached records. Ship and verify mobile first;
keep complete server responses until that prerequisite is met.

## Roadmap priorities

| Priority | Item | Why | When |
|---|---|---|---|
| P0 | Replace artisan serve with PHP-FPM + Nginx | Railway Dockerfile starts the development server; blocked requests stall its default single worker. PHP documents it as unsuitable for public production use. A specific concurrency/502 threshold has not been measured | Before beta launch (Phase 25) |
| P0 | Provider endpoint returns fields missing | Mobile expects has_provider, status, provider_name, provider_phone; backend does not return the expected contract | Before Phase 4 (Auth) |
| P1 | Phase 2.3 SELECT * removal (fully deferred) | Blocked on Phase 2.5: preserve raw key presence before fromJson defaults, merge absent fields while honoring explicit nulls, and fetch details for partial list records | After compatible mobile cache release ships and is verified |
