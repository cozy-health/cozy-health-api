# Sign-in rollout and recovery - 30 October 2026

Development only. General enrollment rollout remains paused. This patch makes pausing safe for accounts that already require protection; it does not authorize production activation.

## Reproduced faults

Four both-role regressions failed before repair: a legacy token without device proof could access private data after SIGNIN_SECURITY_ENABLED became false, and an existing email challenge returned 503 instead of allowing the user to finish setup.

## Final behavior

- The flag controls participation for untouched accounts. Once an account starts protection or is rejected by the protection gate, its existing preference row durably records that protection is required, even before configured_at is set.
- Historical challenge rows from older staged builds also preserve the requirement. Their current-email hash and owner type/ID must match. The next protected operation creates the durable marker without resetting method, answers or email verification.
- Password login, refresh, private access and settings continue to enforce required protection while rollout is paused. A valid trusted device can refresh; a missing proof requires a fresh challenge. Private content stays blocked before confirmed enrollment.
- Existing challenge verification, resend and password-plus-email recovery remain available. Expiry, attempts, one-use tickets, replay rejection, owner/role binding and emergency/support exceptions remain enforced.
- Untouched accounts retain the existing sign-in behavior while rollout is paused. Reading settings does not enroll them or send mail. No schema migration, answer reset or authenticator app was introduced.

## Evidence

Focused SignInSecurityTest: 30 tests passed, 276 assertions. Complete local API suite: 872 passed, 6,446 assertions. Cases cover enrolled accounts, partial enrollment, older challenge-only records, rejected legacy tokens, trusted and untrusted refresh, resend and untouched-account compatibility for patients and providers. Hosted MySQL/release checks and exact deployment identity must be verified after the push; a pending run is not a pass.

Real owned-inbox tests were separately completed against API eda9c769b70ea7df33eb8f55a4cd7fac6a520bba: the owner returned the patient/provider test codes; normal router -> database queue -> worker -> Brevo SMTP -> inbox -> verification succeeded. Fixture tokens were revoked, test accounts disabled, encrypted pending credentials removed from retained summaries and the public flag stayed false. See PROVIDER_SIGNIN_PROTECTION_2026-10-30.md in the provider repository and the workspace delivery report for that acceptance record. No personal sign-in code or credential belongs in this document.

Remaining P02 work includes final compatible-client rollout/activation rehearsal and any still-unverified registration/reset/invitation/resend/bounce matrix entries. Existing Android signing/update continuity and final real-phone testing remain explicit. Keep all other P00-P11/S01 requirements in the current completion plan; this patch does not close those phases.