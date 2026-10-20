# Provider registration and patient invitations

Providers register at `/provider/register` with a name, email, optional practice, and password. Registration creates an active account with an empty roster; it does not certify professional credentials. Existing provider document consent continues to apply. Provider and admin sign-in pages remain separate.

In the mobile app, a patient enters the provider's email under My Provider, selects sharing permissions (all off initially), and sends an invitation. Cozy queues an email with a six-digit code. The patient also sees that code and its expiry, with Copy code and Share invitation actions. Native sharing includes the invited email, portal URL and expiry without health records. The provider signs in using that same email, chooses **Accept patient invitation**, enters the code, reviews the patient's choices, and acknowledges appropriate use and revocation before connecting. Connections with all sharing disabled are supported.

Patients can choose **Wrong provider email?** on a pending invitation. The app validates the corrected address, cancels the specific old pending invitation, and sends a replacement while preserving name and sharing choices. Cancellation failure retains the original invitation; send failure keeps the corrected form for retry. If acceptance wins the race, the app refreshes the accepted connection instead of revoking it.

## Interfaces

- `DELETE /api/v1/provider/invitations/{linkId}`: owning patient cancels a specific pending invitation, clearing its code. Repeated cancellation is safe; an accepted active connection returns 409.
- `POST /api/v1/provider/auth/register`: existing registration contract, now available through the dashboard bridge. Tokens remain inside the encrypted HttpOnly provider cookie.
- `POST /api/v1/provider/request`: optional `verification_actor: "provider"` and `consent_flags` (`moods`, `journals`, `quizzes`, `notes`). Provider-mode invitations queue mail and return the active code only to the owning patient as `provider.invitation_code`, with `invitation_expires_at` and `invitation_portal_url`. Reopening `GET /api/v1/provider` returns the same active code. Provider responses never expose the stored secret. Missing actor retains the legacy patient-verification flow.
- `POST /api/v1/provider/invitations/preview`: authenticated provider supplies `code`. Returns `data.invitation_id`, `patient_name`, `consent_flags`, and `expires_at`, with no wellness data.
- `POST /api/v1/provider/invitations/connect`: supplies `code`, `invitation_id`, the reviewed `consent_flags`, `care_acknowledged: true`, and `revocation_acknowledged: true`. Returns the connected patient ID. Changed scope returns 409 for a fresh review; unavailable codes return 422.

The recoverable code is encrypted at rest, hidden from model serialization, and cleared on acceptance or revocation. Expired codes are not returned or shareable. Existing pending invitations without a stored recoverable code offer resend. Codes expire after 15 minutes. Sending is throttled; resending the same invitation has a one-minute cooldown and invalidates its previous code. Preview and connection share a limit of ten attempts per minute per provider. Code lookup uses a keyed digest scoped to provider email, with a separate password hash for verification. Multiple matches are rejected. Confirmation locks the pending link, checks current scope and validity, records acknowledgement and an audit event, and consumes the code. Patient cancellation and revocation remain authoritative. New invitations cannot be completed through the legacy patient verification endpoint.

Only the selected categories are available after connection. Journal sharing exposes metadata, never journal text, transcripts, or recordings. Existing additional quiz-sharing and provider-verification checks remain in force.

## Deployment

1. Apply `2026_10_15_000000_add_provider_invitation_redemption.php` and `2026_10_16_000003_add_recoverable_provider_invitation_code.php` and deploy the API first. Existing links default to the legacy flow and retain their permissions.
2. Set `PROVIDER_PORTAL_URL` to the HTTPS provider portal URL (ending in `/provider`). Keep SMTP/Brevo and the queue configured. Run the existing worker for the `email` queue. Invitation jobs are encrypted; stale, expired, consumed, or revoked codes are skipped before mail is sent. A queue failure returns a retryable error rather than claiming delivery.
3. Deploy the dashboard, with the existing provider API URL, dashboard origin, and session secret configured.
4. Release the mobile update. Old mobile clients continue to use the legacy contract; new clients wait for provider confirmation and can refresh status, resend, or cancel.

No live migrations, emails, or deployments were performed during implementation. Use a staging patient/provider pair to check SMTP delivery and the installed mobile app after deployment. Watch failed email jobs, invitation attempt throttling, and connection errors. Email arrival is not guaranteed by successful queue submission.

## Validation

API tests cover registration with an empty roster, matching-email redemption including invitations before registration, owner-only code responses, encrypted storage, expiry, resend, cancellation, changed permissions, ambiguous codes, replay, all-private connections, revocation, and queue failure/retry. Existing API and legacy migration tests remain valid. Flutter tests cover permission choices, legacy pending screens, restored codes, clipboard and native sharing, expiry, missing-code resend, email failure/retry, and consent controls. Dashboard browser fixtures exercise registration, secure cookies, empty rosters, preview, acknowledgements, changed scope, roster refresh, replay errors, and narrow layouts.

Run the new browser fixture with `npm run test:provider-onboarding` and `PROVIDER_PLAYWRIGHT_PATH` pointing to an installed Playwright entrypoint. Fixtures are local and do not send real emails. Synthetic previews are stored under `docs/validation/provider-onboarding/`.

## Provider invitation email

The HTML and plain-text email show the current patient name, an optional registered-provider greeting, six-digit code, expiry in UTC, recipient account email, and provider portal link. Instructions cover registration, Accept patient invitation, permission review, and Agree & connect. The patient retains control over sharing and revocation. The subject stays generic; no wellness records appear in the email.

Production mail embeds the Cozy logo as an inline CID attachment. Admin email previews use synthetic names and code 000000 with a data-URI logo. The provider-invitation event is included in the existing delivery registry; delivery logs do not store patient names, recipient addresses, or codes. Expired, replaced, consumed, revoked, or inactive-account invitations are suppressed before sending.

Generate the synthetic registered/new-provider HTML and text previews with `php docs/tools/preview-provider-invitation.php`. This command does not query patient records or send mail. Previews and browser screenshots at 320px and 800px are in `docs/validation/provider-invitation-email/`.

Validation: 584 API tests passed (3963 assertions), including email content, escaped names, inline logo attachment, queue encryption, stale invitation suppression, synthetic previews, and delivery tracking. Browser previews have no horizontal overflow. Actual SMTP delivery and Gmail/Outlook rendering remain staging deployment checks.

## Provider welcome email

New provider registrations queue a dedicated HTML/plain-text welcome email after account and session creation. The email uses the Cozy logo and exact brand colors, links to the configured provider portal, and explains empty rosters and patient invitation redemption. It contains no password, authentication token, or patient information. Queue failure is logged without preventing registration. Existing providers do not receive retrospective emails.

The provider-welcome event is available in admin email delivery tracking and synthetic previews. Queued payloads are encrypted and dispatch after commit. Browser previews at 320px and 800px are stored under docs/validation/provider-welcome; no real emails were sent during validation.
