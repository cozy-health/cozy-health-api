# Admin article contract

All routes are under `/api/v1/admin`, require an authenticated active admin management token with `admin:content`, and return `Cache-Control: private, no-store`.

| Method/path | Behavior |
| --- | --- |
| GET `articles` | Paginated records; optional `search`, `page`, `per_page` (10/20/50). Each record includes `id`, `version`, `published`, `has_draft` and editable `draft`. |
| POST `articles` | Create an unpublished draft; returns 201 and `data`. |
| GET `articles/{uuid}` | Reload the saved draft/live record and its current version without changing it. |
| PUT `articles/{uuid}` | Save `draft` fields with `expected_version`; existing published fields stay unchanged. |
| POST `articles/{uuid}/publish` | Require `expected_version` and `approved: true`; validate licence and draft, then atomically publish it. |
| DELETE `articles/{uuid}` | Require `expected_version`; unpublish without deleting the record or bookmarks. |

Draft fields: `title`, `slug`, `category`, `excerpt`, plain-text `body`, `read_time_minutes`, optional `license`, optional HTTPS `source_url`. Category uses the existing public catalogue slugs. A licence/reference is required to publish. A previously published slug cannot change. Preview is rendered locally from saved/unsaved draft values; it does not publish content or fetch source URLs.

Writes return `{data: {id, version, published, has_draft, draft}}`. Stale writes return 409; invalid input returns 422 with field errors; unauthorized capabilities return 403. A successful unpublish makes subsequent public detail/catalogue requests exclude the article. Draft payloads are hidden from all public article serialization.

This contract adds no author/reviewer role: the existing authorized admin explicitly approves publication. No production migration or publication is authorized by this document.
