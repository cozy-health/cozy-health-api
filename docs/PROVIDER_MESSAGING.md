# Provider messaging

The mobile Provider tab replaces Assistant in this release. AI screens and stored AI conversations remain in the codebase; the old Assistant route redirects to Provider.

## Release

1. Apply the API migration with `php artisan migrate --force` before releasing either client.
2. Deploy the API and provider dashboard, then release the mobile build.
3. Verify a connected patient can send a message, the provider can reply from Messages, and unread counts clear after opening the thread.
4. Share a record, disable its sharing category, and confirm the provider sees an unavailable card after refresh. Revoke the connection and confirm the conversation is inaccessible.

The migration adds `provider_messages`; it does not modify existing AI messages. Message text uses Laravel encrypted casts and requires the existing application encryption key. Record attachments store references and resolve current ownership, deletion status, and consent on each read. Journal cards retain existing provider restrictions: metadata only, without body, transcription, or voice files. Quiz cards use the existing revocable quiz-sharing grants. Notes are existing notes belonging to the connected provider and patient.

## API

Patient routes start at `/api/v1/provider-conversations`; provider routes start at `/api/v1/provider/conversations`. Both expose conversation listing, `/{linkId}/messages` (GET and POST), and `/{linkId}/read` (POST). Patients also have `/{linkId}/records?type=moods|journals|quizzes|notes&page=1`.

Send `{client_id: UUID, content: string, records: [{type, id}]}`. Reuse `client_id` when retrying an unconfirmed send. Records are patient-only; text is limited to 5,000 characters and attachments to ten records. Read markers require `{through: messageId}` and affect only incoming messages in that link. Message listing returns chronological `data` and `has_more`; use `before` for older pages and `since` to revalidate an already loaded range, capped at 1,000 messages. Initial and older pages contain 50 messages.

Clients refresh every five seconds while active. No push, uploads, voice, or typing indicators are included. Watch API error rates and message endpoint latency after release; provider reads and sends use existing access auditing without message text in audit logs.
