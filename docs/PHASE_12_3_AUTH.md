# Phase 12.3: auth hardening

Access credentials issued to native patients/providers expire in 60 minutes.
Existing provider/admin browser limits remain 30 minutes. Opaque random refresh
tokens expire in 30 days, are stored only as SHA-256 hashes in separate owner
tables, and rotate transactionally. Reuse, expiry, disabling, logout and session
revocation cannot restore a session. OAuth also issues refresh credentials.

Five credential failures for a normalized email within 15 minutes lock out all
source IPs. Security events store a keyed email hash, event type and observed IP,
never passwords. The existing IP limits remain separate.

Registration/reset/change/private admin setup reject common passwords and
require a letter, number and at least eight characters (admin setup retains its
twelve-character minimum). Old test fixtures were strengthened, not removed.
Password reset consumes its code and revokes all access/refresh credentials in
one transaction. Authenticated password change preserves only the current pair.

Mobile stores refresh credentials beside access credentials in secure storage;
session-only credentials stay in memory. Concurrent 401s share one refresh.
Failed refresh signs out while preserving account-scoped drafts. Active Sessions
and Change Password now use backend endpoints instead of mock data. Inactivity
uses a secure timestamp, 30 days normally and five minutes on crisis/safety-plan
resume. Existing biometric/PIN gates and session-only background expiry remain.

Verified-email users receive a queued new-device notification once per random
installation ID, including device name, observed IP/time and a support link.
The provider dashboard seals its refresh token inside its encrypted cookie and
never extends its 30-minute browser expiry. Physical-device checks remain pending.
