# Development recovery and email ownership repair

## Behavior

User and provider password-reset codes expire after fifteen minutes and become unusable after five incorrect guesses. Attempts belong to the stored code, independently of caller IP and existing route throttles. They are incremented inside the locked transaction, which commits before returning a rejection. Requesting a new code resets its attempt budget; the existing resend throttles still apply. Incorrect guesses do not change passwords, revoke sessions or disable accounts. Successful resets consume the code and revoke only that account's sessions.

If a password was reset but its confirmation email cannot be queued, the response remains successful and explicitly says the confirmation email could not be queued. Logs contain a generic warning without the code, recipient, password or exception details. SMTP acceptance and inbox delivery are separate outcomes from queue acceptance.

Verification codes have the same five-guess budget. The record contains an HMAC binding to the requested address. Verification locks and reloads the current account before checking that binding. Changing an email through the profile endpoint clears its verified status and old verification code in one transaction. Delayed verification jobs check the account, address binding, current hash, expiry and remaining attempts before sending. Delayed reset jobs also reject exhausted codes.

Existing verification records without the new address binding, and legacy queued verification jobs without an account/address, fail closed. The user must request a fresh code. Admin template previews still render synthetic content without delivering it.

## Reproductions and tests

`RecoverySafetyTest` covers user/provider attempt exhaustion across IPs, verification attempts over multiple throttle windows, a new code after exhaustion, unchanged existing sessions on wrong guesses, completed resets during confirmation-queue failure, patient/provider isolation for a shared email, stale verification jobs, email-change verification reset and rejection of a code sent to a previous address.

Before repairs, tests reproduced successful use of exhausted reset codes, HTTP 500 after an already-completed password change, retained verification after changing an email, and successful verification of a new address with an old address's code. Evidence is saved in workspace output files `recovery-before.log` and `recovery-email-change-before.log`.

The complete application suite runs locally and in hosted development CI. The hosted MySQL contract step additionally includes `RecoverySafetyTest` and `QueuedResetMailTest`, alongside all its existing cases. No checks are removed.

Implementation follows the single-use, expiry, account-scoped protection and recovery-notification guidance in [OWASP's password recovery guidance](https://cheatsheetseries.owasp.org/cheatsheets/Forgot_Password_Cheat_Sheet.html).

## Scope and remaining checks

Only development is authorized. The generic test email has confirmed inbox receipt. Full registration/reset delivery tests need the approved choice of test inboxes; the existing personal account must not be reset. Automatic delivery/bounce reconciliation still needs authenticated Brevo webhook setup and access. A notification that was successfully queued is not proof it arrived.

Security-question enrollment, trusted-device challenges, fourteen-day inactivity checks, sensitive-setting reauthentication and the corresponding mobile/provider UI remain separate unfinished work. This repair does not claim to implement them.
