# Sign-in protection — staged development implementation

`SIGNIN_SECURITY_ENABLED=false` remains the default. This checkpoint implements the server contract, not compatible mobile/provider screens or a completed rollout. Do not enable until both clients can finish challenges, store device proof securely and recover from `signin_verification_required`. No authenticator app or provider professional verification is introduced.

## Protocol

Patient prefix `/api/v1/auth`; provider prefix `/api/v1/provider/auth`. Existing registration/login/refresh return their usual envelope. When a challenge is required, `data` contains `challenge_required: true`, an opaque `challenge_token`, `method` (`email` or `questions`), `expires_at`, up to two `{id,prompt}` questions, `email_delivery` (`queued`, `failed`, `unavailable`, or null), and `support_email`. There is no access or refresh token. Queued means queued, not inbox delivery.

- `POST challenge/verify`: `challenge_token`, plus a six-character `code` or an `answers` object keyed by the challenged IDs. Success consumes the challenge once and returns a session pair and `device_proof`. Email verification also returns a ten-minute, single-use `security_settings_token` for enrollment/change. A settings-only challenge returns that capability without creating a login session.
- `POST challenge/resend`: `challenge_token`. This also provides forgotten-answer email recovery; switching to email invalidates the previous question challenge. The challenge still expires ten minutes after creation. Resend waits sixty seconds, with three sends per account/hour and bounded challenge creation/failed guesses across IPs.
- Authenticated `GET security`: preference, enrolled question IDs, prompt catalog and actual support email; never answers/hashes.
- Authenticated `POST security/reauthenticate`: correct `password`; issues an email challenge for settings changes.
- Authenticated `PUT security`: `method`, `security_settings_token`, and for questions exactly three distinct `{id,answer}` entries. Answers are normalized for case/whitespace then individually password-hashed. Settings tickets are email/account/role-bound and single-use. Outstanding challenges are invalidated after settings change.

Save the random 64-character `device_proof` in platform secure storage or an HttpOnly browser cookie, separately by account/role. Never use a public device ID or fingerprint as proof, put proof in URLs, or expose it to analytics. Send it on login and refresh. Fourteen days without authenticated activity requires verification again. Legacy private sessions are rejected after activation; recovery settings/logout and emergency access remain available. Incorrect passwords cannot request a challenge. Questions cannot reset passwords; forgotten answers support verified-email recovery and Contact support.

Successful login verification includes the current `user` or `provider` identity and `security_enrollment_required`. First email verification does not pretend the owner has selected a preference; `enrolled` becomes true only after a verified settings update. Clients must preserve the initial registration/new-user routing metadata while completing a challenge. Long answers are normalized and SHA-256 digested before the salted password hash, so the entire answer is checked rather than a password-hasher byte-limited prefix.

The rollout must coordinate API, worker, mobile and provider bridge; the bridge must hold provisional challenge state separately and must not set its authenticated session cookie before verification. Existing pending work must survive reauthentication and account changes. Old clients must display a truthful recovery/update message rather than a login loop.

## Validation still required

Local endpoint regression coverage is separate from MySQL concurrency, hosted checks, actual branded-email inbox delivery, native secure storage, browser cookie handling and physical-device acceptance. The full product register remains authoritative. Developer accounts and store-dependent acceptance remain last.
