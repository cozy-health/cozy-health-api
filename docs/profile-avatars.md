# Profile avatars

Patients manage their own picture through authenticated `/api/v1/user/profile/avatar`:

| Method | Request | Result |
| --- | --- | --- |
| GET | none | Current `avatar_url` and monotonic `avatar_revision` |
| PUT | `preset_id`: `open-peeps-01` through `open-peeps-24` | Select bundled artwork |
| POST | multipart `photo` | Replace with a managed photo |
| DELETE | none | Explicitly remove the current picture |

Mutation requests may include `expected_user_id`; a mismatch returns 409 so a
picker opened before an account switch cannot affect the new account. Resource
ownership always comes from the authenticated user, never a supplied owner ID.
All responses use the normal `data` envelope. Presets return an `asset:` reference
understood by the app. Uploaded images return a 15-minute signed HTTPS URL.

JPEG and PNG uploads are limited to 5 MB and 4096 pixels per side. PHP GD decodes
and center-crops to 512×512 JPEG, stripping original metadata. The mobile cropper
already lets users choose their framing before uploading. New objects must be
written successfully before a transaction replaces the profile reference.

Profile GET/restore responses include the canonical avatar. Ordinary profile
updates and `/sync/batch` cannot write avatar fields. Legacy URLs remain readable
until the user chooses or removes a picture. Object keys are hidden from JSON.

## Railway storage

Use the existing project's private `cozy-profile-avatars` bucket in `sjc`, near the
API's US West service. No bucket credentials belong in the mobile app or Git.
Set the following API and worker variables through Railway reference variables:

```text
AVATAR_BUCKET=${{cozy-profile-avatars.BUCKET}}
AVATAR_ENDPOINT=${{cozy-profile-avatars.ENDPOINT}}
AVATAR_REGION=${{cozy-profile-avatars.REGION}}
AVATAR_ACCESS_KEY_ID=${{cozy-profile-avatars.ACCESS_KEY_ID}}
AVATAR_SECRET_ACCESS_KEY=${{cozy-profile-avatars.SECRET_ACCESS_KEY}}
AVATAR_USE_PATH_STYLE_ENDPOINT=false
```

The bucket uses virtual-hosted URLs. Files are private; Laravel signs reads. There
is no production fallback to the ephemeral container filesystem. Missing storage
returns a retryable failure without replacing the old image. PHP GD is required
by Composer and installed in the Docker image; PHP accepts 5 MB file uploads and
6 MB multipart requests. Nginx's existing 10 MB request limit is retained.

Replaced photos and account deletion write cleanup intents transactionally into
`avatar_photo_deletions`. The existing scheduler runs `avatars:cleanup` hourly;
failed deletions remain available for retry. Do not disable the API scheduler.

Deploy the new migration before releasing the mobile build. The Docker startup
already runs migrations. Tests use fake disks and never touch patient photos:

```text
php -d extension=gd vendor/bin/pest --compact
```

After deployment, run `php artisan avatars:check-storage` in the API service. It
checks the actual Laravel filesystem configuration, signed download, denial of
unsigned access, and deletion using only a temporary verification object. It
prints no credentials and does not access any user photo.

Railway storage documentation: https://docs.railway.com/storage-buckets
