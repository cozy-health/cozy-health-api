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
