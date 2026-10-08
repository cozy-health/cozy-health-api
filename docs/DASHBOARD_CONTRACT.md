# Home dashboard (Phase 4.5.1)

`GET /api/v1/dashboard` requires Sanctum authentication and is limited to 60
requests per minute per authenticated user. Query/body user IDs are ignored.
No existing endpoint or user serialization changes.

```json
{
  "success": true,
  "message": "Dashboard retrieved.",
  "data": {
    "today_mood": null,
    "streak_days": 0,
    "weekly_moods": [],
    "recent_entries": [],
    "timezone": "UTC"
  }
}
```

- `today_mood`: latest non-deleted mood from the user's local calendar date,
  or null. Contains only id, mood, intensity, created_at and client_created_at.
- `streak_days`: consecutive occupied local dates, starting today when occupied,
  otherwise yesterday. If neither is occupied, zero. Multiple moods on a date
  count once. The first missing date ends the streak; no seven-day cap.
- `weekly_moods`: occupied dates in the seven-calendar-day window ending today,
  ascending. Each item contains `day` (YYYY-MM-DD), `count` (integer) and
  `avg_intensity` (number, rounded to two decimal places). Empty dates are omitted;
  an empty account returns []. Mobile chart wiring must fill missing dates.
- `recent_entries`: at most five moods, newest first, with the same projection as
  today_mood. ID descending breaks equal-time ties deterministically.
- `timezone`: stored user timezone, with the existing UTC fallback for legacy null.

Event time uses client_created_at; only legacy null client timestamps fall back
to created_at. A newly uploaded older record remains an older mood. Sync-deleted
records and future **calendar dates** are excluded everywhere; the full current
calendar date is included, even if an entry's time is later today.

Local start-of-day and next-day boundaries are converted to UTC. Ranges are
half-open (`>= start`, `< next start`), so midnight is never counted twice and
23/25-hour DST dates are handled correctly. Parameterized CASE expressions
group by these boundaries on both SQLite and MySQL without CONVERT_TZ or timezone
tables. Range predicates retain the existing user/timestamp index columns;
legacy fallback uses a separate null-client branch.

Weekly aggregation reads at most seven output rows. Streak reuses those rows
and queries historical aggregates in batches of 31 calendar dates, stopping at
the first missing date. Raw history is never returned or loaded for streaks.
Recent/today projections never return notes, crisis flags, triggers or other
unneeded content. No migration or dependency is added.

Mobile Home is unchanged in this commit. Its existing Hive-backed streak,
weekday chart and hardcoded labels will be replaced in Phase 4.5.4. This endpoint
does not yet supply Insights or Affirmations; those remain separate sub-phases.
