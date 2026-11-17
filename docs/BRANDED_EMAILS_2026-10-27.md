# Shared customer email branding

All active customer-facing email types use `resources/views/emails/layout.blade.php`: the embedded Cozy logo, blue/green/orange/yellow brand strip, one responsive card, readable content and consistent support footer. Reset and verification codes use the shared code panel. Templates use email-safe tables and inline styles; they do not load external fonts, scripts or tracking pixels. Actual messages embed the logo using CID; synthetic previews render an inline data image.

Coverage includes user/provider welcome, reset requests and confirmations, verification, new-device notices, account deletion, assessment sharing, provider invitations, institution-email confirmation, emergency-contact confirmation and support alerts. The generic queue-check email is branded too. Legacy welcome/reset view filenames delegate to the shared templates. Retired guardian routes remain retired and have not been re-enabled.

`BrandedNoticeMail` supplies both HTML and plain text for previously raw active service notices. It sends synchronously to preserve the existing confirmation error responses and the urgent alert job's single-send policy. Its content is HTML-escaped in the HTML view and preserved literally in the plain-text MIME part, including action URLs. Customer messages added in future should use this notice or extend the shared layout, and be represented by an admin-safe synthetic preview.

Existing queued mail encryption, code expiry/supersession checks, recipient restrictions, consent rules, action URLs and urgent-alert delivery-status semantics remain in place. No billing or guardian functionality was introduced. The admin preview registry includes the generic service notice without exposing real recipients or tokens.

`BrandedEmailTest` covers branding across all twelve registered preview types, actual inline-logo MIME embedding with a plain-text alternative, content escaping and literal plain-text action links. The urgent-alert test continues to require one accepted message, the recipient/name, safety explanation and stop-alert action. Provider invitation tests continue to cover codes, expiry, privacy, escaping and logo attachments.

Browser previews at desktop and 320px phone widths were visually reviewed. Reset and provider invitation measured no horizontal overflow. These are Chromium rendering checks, not a claim to have tested every email client. Preview HTML and screenshots are in workspace `output/playwright/cozy-email-brand/`; `index.html` links all twelve synthetic email examples.

Implementation and testing are development only. A harmless branded queue-check email may be sent to the already approved development inbox after the verified worker deployment; real customer notifications are not broadcast as part of this change.
