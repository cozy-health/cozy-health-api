# Admin assessment publication

Development implementation, 2 November 2026. Existing routes remain under `/api/v1/admin/quizzes` and require an active management token with `admin:content`. The dashboard proxy retains origin/CSRF checks.

- `GET /quizzes` returns paginated drafts with a `version` token.
- `POST /quizzes` saves a draft and returns `data.id` and `data.version`.
- `PUT /quizzes/{id}` requires the current `expected_version`; saving a revision does not replace the live definition.
- `POST /quizzes/{id}/publish` requires `expected_version`, `approved: true`, and saved `definition.scoring_rules.provenance.license` / `approval_reference`. An optional `source_url` must use HTTPS. Provenance stays with the immutable published definition. These fields record the administrator's approval; the software does not establish clinical validity or rights.
- `DELETE /quizzes/{id}` requires `expected_version` and archives the assessment without deleting historical attempts.
- A stale save, publication or archive returns 409. Reload the list and reopen the current draft before retrying; invalid input returns 422.

## Ephemeral score preview

`POST /quizzes/preview` accepts `{definition, answers}`. `definition` uses the editor/import format. `answers` is an ordered list of **zero-based option indexes**, one per question. Imported IDs are ignored; the server generates temporary IDs and uses `AssessmentService::score`, the same scorer used for patient results.

The `data` response contains `score`, `maximum_score`, `interpretation`, `recommendation`, `disclaimer` and `is_crisis_flagged`. It has `Cache-Control: private, no-store` and creates no drafts, definitions, patient attempts, completion analytics or answer-bearing audit entries. Preview selections/results are cleared when the definition changes; changing an answer cancels an outstanding preview and clears its result.

## User visibility and compatibility

Publishing adds the assessment to the existing authenticated `/api/v1/quizzes` catalogue. Existing consent, feature and paid-plan checks remain in place. Archival prevents new attempts; existing history retains its original version and result. Catalogue refresh supports new assessments without reinstalling the app.

Deploy the API with its additive saved-article attribution migration, then the matching dashboard, then mobile. Older admin editors need to reload after the version/approval contract changes. No production rollout or publication of content is implied.

## Provider overview

`GET /api/v1/provider/overview` requires an authenticated active, verified provider and current required provider consent. It returns `{data: {checked_at, totals: {connected_patients, unread_messages, shared_assessments}}}` with `private, no-store`; filters are unsupported. Counts cover all accessible records, using the same eligible connections as the roster/messages and the same current grants as the shared-result inbox. No names, answers or clinical aggregates are returned. Refreshing and returning to Overview fetch a new snapshot.
