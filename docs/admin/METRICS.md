# Aggregate metric definitions and privacy contract

Version 1. Reports use UTC and immutable monthly releases. Release `2026-10-01`
ends at midnight October 1, excluding October activity. A release is created once
on first access and persisted in `admin_metric_releases`. Refresh, pagination,
cache clear, edited records and account deletion do not regenerate a release.
No arbitrary dates, user filters, demographics, export or drill-down are exposed.
Later monthly releases may include different retained source rows.

## What is measured

Population: current active non-demo consumer accounts (`role=user`, not soft
deleted) at release creation. Operators, providers and demo fixtures are excluded.
Source: retained, non-deleted `mood_entries`, published non-draft `journal_entries`,
and completed `quiz_attempts`. Source rows use server `created_at` in UTC, not
client timestamps; this measures recorded/synced creation, not every app visit.
Edits/deletions, delayed sync and missing instrumentation limit historical coverage.
No full text, titles, answers or contact details are loaded by aggregation.

- Daily activity: distinct record creators per UTC day during the last 30 complete
  days before release. This is the defined record-based DAU, not session-based DAU.
- Signups: weekly consumer signup cohorts, Monday through next Monday, covering
  the last twelve complete weeks before release. Related totals can be suppressed.
- Feature usage: retained created records over the fixed 7/30/90 days before
  release. Edits do not count as new records; deleted records are absent. Features
  with anonymous-only event instrumentation (app opens/logins/crisis hub opens)
  are unavailable because distinct contributing users cannot be proved.
- Retention: signup-week cohorts. D1/D7/D30 mean at least one retained activity
  record on that exact UTC calendar day after each user's signup day. A horizon
  is immature until even the last signup day of that cohort has its complete
  target day before the release cutoff. This is recorded-activity retention.
- Safety: total stored crisis-flagged records across the same three sources,
  not unique people, incidents, severity, diagnosis or verified clinical risk.
  Repeated records can produce multiple flags; no content is returned.

## Suppression and composition

All counts below five **distinct people** are suppressed; repeated events from
one person never establish a publishable cohort. Suppressed cells contain only
`status=suppressed` and `count=<5`; exact hidden values are not stored in release
payloads or returned to the browser. Display status may conservatively suppress
larger cells to protect related groups. No distinct contributor count is exposed
for feature/safety totals.

Numeric counts are rounded down to multiples of five. Retention rates use released
rounded numerators and denominators, never exact hidden values. The rate is an
approximation. Do not interpret a displayed five as exactly five individuals/events.

Usage 7/30/90 totals use the same non-overlapping components: last 7 days, preceding
23 days, preceding 60 days. Each component must contain five distinct contributors
for that feature; if one component is unsafe, all three related feature totals
are suppressed. Totals sum rounded components, so range subtraction cannot recover
an exact small remainder.

Safety similarly requires five flagged contributors AND five contributors without
any flagged record in each component. All related ranges share suppression. No
unflagged total or person count is returned. Retention requires five retained and
five non-retained members per mature horizon, plus five members in every observed
joint D1/D7/D30 activity pattern. If any mature horizon/pattern is unsafe, all
mature cells and related signup totals for the cohort are suppressed. Immature
cells disclose no cohort size or rate. Weekly cohorts are disjoint.

Backend enforcement is authoritative; direct API calls receive the same values as
charts/tables. The legacy `/admin/metrics/analytics` alias now uses the same usage
contract and scoped admin session; it no longer publishes anonymous event totals
based on an unrelated population size. The persisted release contains aggregate
output only. Internal identifiers used for distinct counting never leave the
aggregation process.

This implements conservative threshold/complement suppression and coarse counts,
not a formal differential-privacy guarantee against all external auxiliary data.
Do not add flexible filters, exact totals, new overlapping breakdowns, different
rounding or live recomputation without a new composition/privacy review.

## Endpoints and operations

`GET /api/v1/admin/metrics/{overview,usage,retention,safety}` and root
`/admin/metrics/*` aliases require admin role, active account, a scoped
`cozy-admin-dashboard` bearer credential and rate limits. Only usage accepts
`range=7d|30d|90d`. All responses are private/no-store. The dashboard cookie is
separate and bridged server-side; provider/patient sessions are not accepted.

Health cards measure this successful API request and a database connectivity
probe with a UTC check timestamp. Historical availability/uptime, P95/P99 latency,
error rate, queue processing health and deployment version are unavailable until
trusted monitoring sources are connected. A queue row count alone is not evidence
of a healthy worker. No logs, exceptions or credentials appear in these cards.

Use shared database-backed cache locks across replicas. A release build uses a
five-minute lock and database transaction. Monitor larger datasets: the current
implementation streams selected activity columns but retains actor/day sets in
memory for distinct counting. It is suitable for the current launch-scale data;
measure memory/query duration before higher-volume operation. Do not delete or
rewrite published releases to refresh numbers: that would permit differencing.
Back them up with the database. A separate approved lifecycle for old aggregate
releases can delete whole obsolete releases; do not regenerate deleted versions.

Tests cover 1/4/5 distinct actors, repeated records, range complements, joint
retention patterns, immaturity, rounded counts, cache clears/edits and identity
exclusion. Browser fixtures verify rendering/auth/empty/errors; they do not assert
actual production population counts or substitute for deployment verification.
