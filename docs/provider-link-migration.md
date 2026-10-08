# Provider link consolidation (PD-3.0)

`provider_client_links` becomes the sole operational link table. The legacy
`provider_connections` table remains unchanged as a deployment safety net.
Do not drop it until PD-6 and a separate verified cleanup migration.

Deploy with patient linking writes paused. Back up both tables first. MySQL DDL
is not transactional. The migration checks legacy records before changing the
schema and refuses invalid emails, missing owners/providers, or conflicting
links. A conflict needs explicit reconciliation; no record is silently discarded.

Each copied link keeps its user, provider/email, contact details, verification
timestamp, pending verification hash and expiry. `linked_at` uses `verified_at`
for verified records and original `created_at` for pending invitations. All four
consents on copied rows are false. Existing PD-2 links keep their consents.

`legacy_connection_id` records provenance. Verify the legacy count equals the
count of non-null provenance values, and compare each mapped row before resuming
writes. Pending invitations must never qualify for provider client-data access.

An immediate rollback removes only copied rows and added columns; it preserves
the legacy table and pre-existing PD-2 links. Do not use rollback after serving
new linking traffic: restore/export and reconcile new data first. Rollback refuses
new email-only invitations which cannot fit the previous non-null provider schema.

Registration attachment and patient notifications are separate from this schema
copy. Patient acceptance notifications remain for Phase 18. No production
migration or deployment is performed by this development task.
