# Scheduler heartbeat

`ops:heartbeat` runs once a minute with overlap protection. It stores only a
timestamp under `ops:scheduler:last-tick`, expiring after ten minutes.
`ops:status --verify` requires a heartbeat from the last three minutes.

The previous scheduled closure returned `Cache::put()` directly. On MySQL,
writing the same timestamp and expiry twice can report zero changed rows, so
Laravel returned false and treated the scheduled task as failed even though
the heartbeat was correct. This was reproduced with two same-second writes:
the first event exited 0 and the second exited 1 with identical stored data.

The named command accepts that false result only when the expected timestamp
is already present. Missing data and cache exceptions still produce a nonzero
exit code and a safe, task-specific error message. No public API, schema, or
heartbeat freshness threshold changes.

Regression checks cover repeated writes, expiry, false writes with and without
matching data, and storage exceptions. The isolated MySQL heartbeat fixture
also checks eight concurrent same-second writers and expiry. It runs after the
affirmation fixture in the MySQL workflow, with the same dedicated-database
guard; it must never target an application database.

After deployment, confirm API and worker health, inspect the named task logs,
and observe at least five distinct heartbeat timestamps without heartbeat
errors. Do not run a complete production schedule manually to verify it: other
tasks can send reminders or remove expired data.
