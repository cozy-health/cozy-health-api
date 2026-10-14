# Provider registration and patient invitations

Providers register at `/provider/register` with a name, email, optional practice, and password. Registration creates an active account with an empty roster; it does not certify professional credentials. Existing provider document consent continues to apply. Provider and admin sign-in pages remain separate.

In the mobile app, a patient enters the provider's email under My Provider, selects sharing permissions (all off initially), and sends an invitation. Cozy queues an email with a six-digit code. The provider signs in using that same email, chooses **Add patient**, enters the code, reviews the patient's choices, and acknowledges appropriate use and revocation before connecting. Connections with all sharing disabled are supported.

## Interfaces

- `POST /api/v1/provider/auth/register`: existing registration contract, now available through the dashboard bridge. Tokens remain inside the encrypted HttpOnly provider cookie.
- `POST /api/v1/provider/request`: optional `verification_actor: "provider"` and `consent_flags` (`moods`, `journals`, `quizzes`, `notes`). Provider-mode invitations queue mail and never return the code, including in local responses. Missing actor retains the legacy patient-verification flow.
- `POST /api/v1/provider/invitations/preview`: authenticated provider supplies `code`. Returns `data.invitation_id`, `patient_name`, `consent_flags`, and `expires_at`, with no wellness data.
- `POST /api/v1/provider/invitations/connect`: supplies `code`, `invitation_id`, the reviewed `consent_flags`, `care_acknowledged: true`, and `revocation_acknowledged: true`. Returns the connected patient ID. Changed scope returns 409 for a fresh review; unavailable codes return 422.

Codes expire after 15 minutes. Sending is throttled; resending the same invitation has a one-minute cooldown and invalidates its previous code. Preview and connection share a limit of ten attempts per minute per provider. Code lookup uses a keyed digest scoped to provider email, with a separate password hash for verification. Multiple matches are rejected. Confirmation locks the pending link, checks current scope and validity, records acknowledgement and an audit event, and consumes the code. Patient cancellation and revocation remain authoritative. New invitations cannot be completed through the legacy patient verification endpoint.

Only the selected categories are available after connection. Journal sharing exposes metadata, never journal text, transcripts, or recordings. Existing additional quiz-sharing and provider-verification checks remain in force.

## Deployment

1. Apply `2026_10_15_000000_add_provider_invitation_redemption.php` and deploy the API first. Existing links default to the legacy flow and retain their permissions.
2. Set `PROVIDER_PORTAL_URL` to the HTTPS provider portal URL (ending in `/provider`). Keep SMTP/Brevo and the queue configured. Run the existing worker for the `email` queue. Invitation jobs are encrypted; stale, expired, consumed, or revoked codes are skipped before mail is sent. A queue failure returns a retryable error rather than claiming delivery.
3. Deploy the dashboard, with the existing provider API URL, dashboard origin, and session secret configured.
4. Release the mobile update. Old mobile clients continue to use the legacy contract; new clients wait for provider confirmation and can refresh status, resend, or cancel.

No live migrations, emails, or deployments were performed during implementation. Use a staging patient/provider pair to check SMTP delivery and the installed mobile app after deployment. Watch failed email jobs, invitation attempt throttling, and connection errors. Email arrival is not guaranteed by successful queue submission.

## Validation

API tests cover registration with an empty roster, matching-email redemption including invitations before registration, hidden code fields, expiry, resend, cancellation, changed permissions, ambiguous codes, replay, all-private connections, revocation, and queue failure/retry. Existing API and legacy migration tests remain valid. Flutter tests cover pre-invitation permission choices, legacy pending screens, resend state, and consent controls. Dashboard browser fixtures exercise registration, secure cookies, empty rosters, preview, acknowledgements, changed scope, roster refresh, replay errors, and narrow layouts.

Run the new browser fixture with `npm run test:provider-onboarding` and `PROVIDER_PLAYWRIGHT_PATH` pointing to an installed Playwright entrypoint. Fixtures are local and do not send real emails. Synthetic previews are stored under `docs/validation/provider-onboarding/`.
