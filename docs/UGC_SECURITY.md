# User-generated content security preparation

UGC is not enabled by this phase. Publishing it requires the controls below.

## HTML sanitization

Prefer plain text. If rich text is required, sanitize on the server with an explicit tag and attribute allowlist; reject scripts, event handlers, dangerous URL schemes, embedded frames and inline styles. Render through the same safe renderer on mobile and web. Test stored and reflected XSS before launch.

## Image upload validation

Validate decoded file type, dimensions, byte size and allowed MIME types, independently of filename and client headers. Reject SVG and executable/polyglot uploads. Re-encode accepted raster images and remove EXIF/location metadata. Store privately with generated filenames; authorize every download. Apply upload rate limits and retention/deletion rules.

## Moderation API plan

Run server-side moderation before publication with timeouts, retry limits and a review queue. Keep rejected/pending content private when the service is unavailable. Minimize data sent to vendors, obtain the required consent, document vendor retention, and keep user text out of operational logs. Clinical crisis support must remain available independently of moderation outcomes.

## Report and block readiness

Add authenticated, rate-limited reports with a bounded reason and no public reporter identity. Prevent blocked accounts from interacting in either direction. Provide operator review, appeal, audit and removal workflows with least privilege. Verify authorization, abuse limits and account deletion behavior before enabling any community feature. These workflows remain implementation requirements, not completed features.
