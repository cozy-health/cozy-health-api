# Provider resources and reports (PD-4)

All provider endpoints below require `auth:provider` and an active provider account.
Client assignment/report access also requires an unrevoked, verified patient link.
There is no additional resources consent flag. Crisis features are unchanged.

## Resource library

- `GET /api/v1/provider/resources`: own resources, paginated.
- `POST /api/v1/provider/resources`: required `title`, `type`; optional
  `duration_minutes`, `description`, `url` (HTTP/HTTPS).
- `PATCH /api/v1/provider/resources/{id}`: same fields, own resources only.
- `DELETE /api/v1/provider/resources/{id}`: deletes the resource and its
  assignments under the existing cascading foreign key.

Resource updates and cascading assignment deletions log each affected patient
assignment. Assignments reference the current resource contents.

## Assignments

- `GET /api/v1/provider/clients/{user_id}/resources`: own assignments to this
  linked patient, with resource details.
- `POST /api/v1/provider/clients/{user_id}/resources`: `resource_id` must belong
  to the authenticated provider; optional `notes` up to 5,000 characters.
- `DELETE /api/v1/provider/clients/{user_id}/resources/{assignment_id}`: removes
  only this provider's assignment to this patient.
- `GET /api/v1/me/resources`: patient auth; own assignments from active links,
  with `provider: {id, name, practice}` and resource details.
- `PATCH /api/v1/me/resources/{assignment_id}`: patient auth; `status` is
  `completed` or `declined`. Completion sets `completed_at`; declining clears it.

Each provider assignment read/write is logged with resource type `assignment`.
All list endpoints accept `page` and `per_page` (default 20, maximum 100).
Cross-provider/patient IDs, pending links and revoked links return 404.

Mobile: Settings → My Provider → Resources. Sharing remains the initial tab.
Resources include provider guidance, safe external links, pagination, refresh,
and complete/decline controls. Failed updates preserve the visible prior status.

## PDF reports

- `GET /api/v1/provider/reports`: own reports for currently active patient links.
- `POST /api/v1/provider/reports`: required `user_id`; optional `type: monthly`;
  optional paired `from`/`to` dates (`YYYY-MM-DD`). Returns `{report}` with 201.
- `GET /api/v1/provider/reports/{id}`: returns `{report}`.
- `GET /api/v1/provider/reports/{id}/download`: returns
  `{download_url, expires_at}` with a one-hour signed relative URL. Resolve
  `download_url` against the API origin; signing the path/query supports TLS proxies.
- The signed URL targets `GET /api/v1/provider/reports/{id}/file`. Fetch it with
  the provider's authentication token; returns PDF bytes as an attachment.

Default period is the current calendar month through today in the provider's
timezone. Explicit dates include both days; periods are limited to one year.
PDFs are stored on the private local disk at
`storage/app/private/provider-reports/{provider_id}/{uuid}.pdf`.
Internal paths and consent/link snapshots never appear in JSON responses.

Reports include only currently consented sections. Journal summaries contain
counts and types only: no titles, bodies, transcripts, prompts or recordings
are fetched for generation. Notes include only this provider's notes in the
period. Deleted records, draft journals and other patients' entries are excluded.
Text is escaped and PDF remote resources, PHP and JavaScript are disabled.

Generation, report list/detail reads, URL issuance and PDF downloads are audited.
Downloads recheck ownership, active link, original verification timestamp and
every consent represented in the stored PDF. Revocation, reconnecting, or turning
off a represented consent blocks old PDFs with 404. Generate a new report using
current consent. Already downloaded copies cannot be recalled.
Reports predating consent snapshots cannot be opened or downloaded.
The PDF response is private/no-store. Signed URLs cannot bypass provider auth,
and unsigned, tampered or expired URLs are rejected.

Migration `2026_10_09_000004_add_provider_report_consent_snapshot` adds nullable
consent and link-verification snapshots plus a nullable link foreign key.
It does not modify legacy `provider_connections` or existing patient consents.
Device smoke tests remain scheduled for the end of PD-7; code delivery remains
deferred until before beta. Dashboard integration belongs to PD-5.
