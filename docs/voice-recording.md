# Voice journals and provider messages

Deploy the API before the mobile app and provider dashboard. Run `php artisan migrate --force` during deployment. Existing journal and text-message records remain compatible; old voice entries with no audio file display an unavailable message.

The API requires FFmpeg and FFprobe to validate audio, measure duration and convert mobile M4A and browser WebM/Ogg/MP4 into mono AAC/M4A for cross-platform playback. The Docker image installs FFmpeg. On other hosts, install both binaries and optionally set `AUDIO_FFMPEG_BINARY` and `AUDIO_FFPROBE_BINARY` to their executable paths. The API process needs a writable temporary directory and permission to launch these processes. The source input is limited to 20 MB and recording duration to five minutes. PHP and nginx limits include multipart overhead.

`AUDIO_FILESYSTEM_DISK` defaults to Laravel's private local disk (`storage/app/private`). Mount that directory on persistent storage in production, or select a configured private object-storage disk shared by all replicas. Never use the public disk. Keep bucket credentials on the API server. Audio routes require authentication and do not return public storage URLs.

Patients upload to `POST /api/v1/audio-attachments`; providers use `POST /api/v1/provider/audio-attachments`. Multipart fields are `audio`, `client_id` (UUID), `duration` (seconds), `purpose` (`journal` or `message`), and `provider_client_link_id` for messages. The response contains an attachment `id` and the measured `duration`. Retrying the same owner/client ID returns the same attachment. Changing the purpose or conversation for that ID is rejected.

Journal sync sends `voice_url: /audio-attachments/{id}` after uploading. Direct journal writes may also supply `audio_attachment_id`. The server checks ownership and journal purpose before saving. Provider messages accept `audio_attachment_id` with the existing message `client_id`; text is optional for an audio message. Message responses add nullable `audio: { id, duration, media_type }`.

Playback uses the authenticated `GET .../audio-attachments/{id}` route and supports byte ranges. Providers use their existing same-origin dashboard bridge. Journal audio remains owner-only, including when journal summaries are shared. Message audio requires an active relationship and is visible to the recipient only after sending. Browser drafts are held in memory until sent or discarded. Failed sends retain the recording while the popup stays open.

Mobile journal audio is copied into account-scoped application storage before saving. Offline entries remain in the existing sync queue. Upload acknowledgements are cached per entry so metadata retries reuse the attachment; device paths are excluded from sync payloads. Audio downloaded for journal playback is cached for offline use. Chat playback rechecks server authorization when first loaded.

The existing scheduler runs `audio:cleanup` daily. It removes uploads older than seven days that have no live journal or message reference, including abandoned uploads and deleted journal recordings. Run `php artisan audio:cleanup` to perform the same cleanup manually. Deleted relationship rows preserve attachment metadata until cleanup so storage objects can be removed. Mobile account-data clearing removes that account's audio folder.

Verify deployment with a short journal recording, an offline save and retry, patient-to-provider and provider-to-patient messages, and playback on iOS, Android and supported browsers. Monitor failed audio requests, storage errors and scheduler execution using existing application logging; do not log audio bodies or bearer credentials.
