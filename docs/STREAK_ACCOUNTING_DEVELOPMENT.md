# Streak accounting (development)

Owner-approved rule: seven saved daily mood check-ins earn one rest day, with a maximum bank of two. A completed missed day automatically consumes a credit. The streak count stays at seven; the next actual check-in makes eight. Protected days interrupt the next seven-day earning run. Today is not missed until account-local midnight.

`GET /api/v1/wellness/streak` requires the current authenticated account and returns current/best counts, bank, earning progress, completed/protected local dates, timezone and local date. Dashboard `streak_days` uses the same calculation. All plans use this accounting.

The account-owned ledger records source UUIDs and original calendar dates without mood contents. Retries and edits cannot move an already credited source to another day. Multiple moods on a day count once. Deterministic replay reconciles late offline check-ins and restores a credit when its protected day becomes completed. Account deletion cascades to the ledger; mood deletion retains already earned progress. Existing history seeds progress and achievements, but credits only accrue from the account's first initialization date onward.

The mobile streak detail reads confirmed server progress, explains protection and shows seven calendar days. Failure offers retry; it does not invent a bank from local mood records. Offline saves are reconciled after they reach the API. A fully offline rest-bank projection and real-phone/timezone-change acceptance remain separate outstanding work. No mascot is used.

Migration: `2026_10_28_180000_create_streak_accounting.php`. Deploy the API migration before using the new mobile endpoint. Development only; no production or store release is authorized.
