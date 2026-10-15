# Primary next-of-kin contact

Patient endpoints require Sanctum authentication and operate solely on the
authenticated user; supplied `user_id` and unrelated fields cannot select an owner.

| Method | Path | Behavior |
| --- | --- | --- |
| GET | `/api/v1/next-of-kin` | Read the primary contact or timestamped removal |
| PUT | `/api/v1/next-of-kin` | Validate and replace the primary contact |
| DELETE | `/api/v1/next-of-kin` | Remove the contact details, retaining a sync tombstone |

PUT fields: `name` (required string, maximum 100 characters), `phone` (required
international number), `relationship` (optional string, maximum 100 characters),
and optional `client_updated_at` (ISO timestamp; defaults to server time for
direct endpoint calls). Phone formatting spaces, periods, parentheses, and hyphens
are removed; the result must match `^\+[1-9]\d{6,14}$`. The server rejects timestamps
more than five minutes in the future.

Responses use the normal `{ "data": ... }` envelope. A never-configured contact
returns `data: null`. Otherwise data contains `id: "primary"`, `contact` (name,
phone, relationship), and `client_updated_at`. A removal returns `contact: null`.
Repeated or older timestamped operations do not overwrite a newer version.

Offline sync uses `/api/v1/sync/batch` with type `next_of_kin`, id `primary`, action
`upsert` or `delete`, and `data.client_updated_at`. Upserts include the flattened
contact fields in `data`; deletes also carry their timestamp in `data`. Existing
per-operation success/failure envelopes apply. Invalid fields return validation
errors and cannot write contact data.

The migration creates one row per user. Contact fields are encrypted using the
application key; user deletion clears the record. Deploy with the normal
`php artisan migrate --force` release procedure before the mobile release.

These endpoints store contact configuration only. They send no SMS, push, or
email, and have no help-message endpoint. The mobile app opens the user's SMS app
after review; the user presses Send there. Do not claim delivery from these APIs.

Run `php artisan test --compact --filter=NextOfKin` for authentication, account
ownership, validation, encryption, synchronization, stale replay, and deletion
coverage. Run the full backend suite before release.
