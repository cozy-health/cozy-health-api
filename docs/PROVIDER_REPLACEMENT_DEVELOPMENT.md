# Provider replacement development contract

Patients with the existing provider entitlement may invite another provider after 168 elapsed hours without a reply to the first unanswered saved patient message. Further patient messages and read receipts do not reset that wait. Existing draft/failed sends are excluded.

- `GET /api/v1/provider` adds `replacement` eligibility, qualifying-message/time and latest-attempt state.
- `POST /api/v1/provider/replacements` accepts `original_link_id`, `provider_email` and UUID `request_id`. Identical retries return the same attempt; changed/superseded requests conflict.
- `DELETE /api/v1/provider/replacements/{requestId}` cancels the specific pending attempt. Expiry/cancellation keeps the original connection; stale cancellation cannot affect a newer attempt.
- Replacement acceptance reuses provider-email invitations, keeps an already-started choice despite a late reply, revokes original access/sharing atomically and starts replacement sharing off. No message/record transfer or directory is added.
- Apply additive migration `2026_11_05_000001_add_provider_replacements.php` before the new code serves requests. Replay receipts cascade on account/link deletion. Existing ordinary invitations remain compatible.

Regression coverage includes elapsed boundaries, entitlement expiry, idempotency, mail-queue rollback, late replies, superseded attempts, original revocation and fresh sharing. `tests/Concurrency/mysql_provider_replacements.php` runs only against the guarded disposable loopback CI database; it tests duplicate start and accept/cancel/revoke races. The existing release workflow runs it after the customer-operations checks.

Support/privacy use the existing [separate reviewer assignments](CUSTOMER_OPERATIONS.md); the selected development reviewer is `cozyhealth.hq@gmail.com`, configured by environment IDs rather than granting all admins access. The customer-facing support mailbox remains `team@cozyhealth.org`. Student email eligibility continues to require individually attested student-only domains; an educational suffix alone grants nothing. Document review remains available.

Development validation recorded for this batch: API 993 tests, MySQL selection 286 tests plus all three concurrency runners, Flutter 1,273 tests, explicit development smoke, Android debug build, dashboard strict typecheck/59 unit tests/build and 14 support/privacy browser checks. Real inbox, installed-device, staff coverage, genuine Student domains and production rollout remain separate acceptance gates. No production authorization is implied.
