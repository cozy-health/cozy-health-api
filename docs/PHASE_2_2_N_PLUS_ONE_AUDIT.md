# Phase 2.2 — N+1 query audit

Verified on 2026-10-07. No N+1 cases were found in the implemented endpoints.
No controller, model, route, migration, SELECT list, or response shape changed.
Adding unused relationships would add queries and serialized response fields.

## Source audit

The paginated model queries below are scoped to the authenticated user's
`user_id` by their model global scopes. Pagination executes a count and a page
query. ApiController::success serializes the returned models directly. The
models have no appended accessors or default eager loads that query relations.

| Controller method | Query | Relationships accessed after retrieval | Extra queries per row | Recommendation |
|---|---|---|---|---|
| MoodEntryController::index | MoodEntry ordered by client_created_at descending, paginate(per_page, default 20) | None; triggers and body_sensations are JSON attributes | 0 | No change |
| JournalEntryController::index | JournalEntry ordered by client_created_at descending, paginate | None; tags are JSON, linked_mood_entry_id is returned as an attribute | 0 | No change |
| NotificationController::index | AppNotification ordered by client_created_at descending, paginate | None; no actor relationship exists | 0 | No change |
| ChatController::index | ChatConversation ordered by updated_at descending, paginate | None; last_message_preview is stored on the conversation | 0 | No change |
| ChatController::show | ChatConversation::find(id), with user scope | None; does not retrieve messages | 0 | Audit message rendering when implemented |
| SafetyPlanController::show | SafetyPlan where user_id = current user and is_deleted = false, first | None; plan sections are JSON attributes | 0 | No change |
| QuizController::index | QuizAttempt ordered by client_created_at descending, paginate | None; answers are JSON attributes | 0 | No change |
| ProviderController::show | Current user's providerConnection, then load('provider') | One connection and one provider, no result loop or client list | 0 per list row; 2 fixed relationship queries | Existing load is sufficient |
| ContentController::index | SavedArticle ordered by client_created_at descending, paginate; not registered in routes/api.php | None; title and category are stored attributes | 0 | No change; HTTP verification awaits a route |
| SubscriptionController::show | SubscriptionStatus where user_id = current user, latest(expires_at), first | None | 0 | No change |
| SafetyPlanController::index | SafetyPlan ordered by updated_at descending, paginate; not routed | None | 0 | No change |
| SubscriptionController::index | SubscriptionStatus ordered by updated_at descending, paginate; not routed | None | 0 | No change |
| JournalController::index | Current user's journals(), latest(entry_date), latest(), get; not routed | None after retrieval | 0 | No change |
| SupportController::index | Current user's supportReports(), latest(), get; not routed | None after retrieval | 0 | No change |

CommunityPostController, ContentArticleController, SavedArticleController, and
ChatMessageController do not exist. ContentController is the existing
saved-article implementation, but has no HTTP route. Deferred feature audits
are tracked in [DEFERRED.md](DEFERRED.md).

## Runtime query counts

Used disposable Docker MySQL 8.0.46 on 127.0.0.1:3306, database
`cozy_docker_verify`. A temporary runner overrode the complete MySQL connection
configuration (including URL) before connecting. The configured staging
connection and .env were not used for database operations or modified.

Seeded 20 rows each for moods, journals, notifications, conversations, quiz
attempts, and saved articles, plus messages and a safety plan, subscription,
and provider connection. Routed endpoints were exercised through Laravel's
HTTP kernel with real Sanctum bearer authentication and JSON serialization,
not through a listening HTTP server. Every request returned HTTP 200.
The query log captured all database operations through response serialization.

For repeatability, the disposable token's last_used_at was reset before each
request, outside the measured interval. Authentication therefore contributed
exactly 3 queries: token lookup, user lookup, and token timestamp update.
Normally that update may be omitted within the same timestamp interval.

| Routed endpoint | Data queries | Total with per_page=1 | Total with per_page=20 | Before / after implementation |
|---|---|---|---|---|
| GET /api/v1/mood-entries | 2 | 5 | 5 | Unchanged; no fix required |
| GET /api/v1/journal-entries | 2 | 5 | 5 | Unchanged; no fix required |
| GET /api/v1/notifications | 2 | 5 | 5 | Unchanged; no fix required |
| GET /api/v1/conversations | 2 | 5 | 5 | Unchanged; no fix required |
| GET /api/v1/quiz-attempts | 2 | 5 | 5 | Unchanged; no fix required |
| GET /api/v1/conversations/{id} | 1 | 4 | 4 | Unchanged; single conversation |
| GET /api/v1/safety-plan | 1 | 4 | 4 | Unchanged; single plan |
| GET /api/v1/provider | 2 | 5 | 5 | Unchanged; single connection/provider |
| GET /api/v1/subscription | 1 | 4 | 4 | Unchanged; single subscription |

The five routed lists returned exactly 1 and 20 records respectively. Changing
per_page does not change the number of returned resources for show endpoints.
These are row-scaling measurements of unchanged code, not invented before/after
optimization results.

Direct invocation of the unrouted ContentController::index returned 1 and 20
saved articles with 2 queries in each case. Unrouted safety/subscription index
methods also executed 2 queries each, returning their single seeded record.
Legacy journal/support index methods executed 1 query each on empty collections;
their no-N+1 conclusion additionally rests on source inspection. Direct counts
exclude authentication setup and are not HTTP endpoint measurements.

## Result and validation

- N+1 cases found: 0. Eager loading added: none.
- Cartesian explosion mitigations: none needed; no child collections rendered.
- Eloquent with() normally fetches relations in separate queries rather than
  producing SQL join multiplication; the concern for future collections is
  unbounded child retrieval and memory use.
- php artisan route:list --path=api/v1 --except-vendor: 44 registered routes.
- php artisan test: 23 passed, 93 assertions, using the existing SQLite test setup.
- Disposable container, its volume, temporary runner, and token fixture removed.
- No push or staging/production migration execution.

Phase 2.2 is complete for the implemented surfaces. Phase 2.3 can proceed.
