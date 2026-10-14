<?php

return [
    'provider' => ['portal_url' => env('PROVIDER_PORTAL_URL', 'https://v0-provider-dashboard-component.vercel.app/provider')],
    'brevo' => ['webhook_token' => env('BREVO_WEBHOOK_TOKEN')],
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'ios_client_id' => env('GOOGLE_IOS_CLIENT_ID'),
        'android_client_id' => env('GOOGLE_ANDROID_CLIENT_ID'),
    ],

    'apple' => [
        'service_id' => env('APPLE_SERVICE_ID'),
        'bundle_id' => env('APPLE_BUNDLE_ID'),
        'team_id' => env('APPLE_TEAM_ID'),
        'key_id' => env('APPLE_KEY_ID'),
        'private_key' => env('APPLE_PRIVATE_KEY'),
        'redirect_uri' => env('APPLE_REDIRECT_URI'),
    ],
];
