# App configuration

`GET /api/v1/config` is public. Responses cache database values for 60 seconds; server_time is generated per request. Model writes invalidate the cache immediately. Mobile caches for 60 minutes and refreshes every ten minutes while open.

```sh
php artisan flags:set ai_assistant false
php artisan settings:set maintenance_mode true
php artisan settings:set maintenance_message "We're doing some quick maintenance. Back in a moment."
php artisan settings:set min_app_version 1.0.1
php artisan settings:set latest_app_version 1.0.2
```

Settings are also editable via authenticated administrator `PUT /admin/settings/{key}` (or `/api/v1/admin/settings/{key}`) with a JSON `value`. Promote an existing user with `user:make-admin`; registration/profile updates cannot assign roles.

Configure `update_ios_url` and `update_android_url` to real HTTPS App Store and Google Play listing URLs **before raising min_app_version**. They are intentionally null until actual listings exist. Versions use major.minor.patch. latest_app_version drives the dismissible soft-update banner, independent of the blocking minimum version.

Maintenance is app-level: configuration and crisis resources remain available. Do not use `artisan down` for this switch: it would also block config and safety-plan APIs. Crisis hub, breathing, grounding and safety plan are never flags and remain reachable from both mobile blocking screens.
