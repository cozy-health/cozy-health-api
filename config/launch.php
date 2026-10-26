<?php

return [
    'website_url' => 'https://cozyhealth.org',
    'billing_enabled' => env('STORE_BILLING_ENABLED', false),
    // Populate only after developer enrollment and approved product configuration.
    'products' => json_decode(env('STORE_PRODUCTS_JSON', '{}'), true, 512, JSON_THROW_ON_ERROR),
    'apple' => ['issuer_id' => env('APPLE_STORE_ISSUER_ID'), 'key_id' => env('APPLE_STORE_KEY_ID'), 'private_key' => env('APPLE_STORE_PRIVATE_KEY'), 'bundle_id' => env('APPLE_STORE_BUNDLE_ID'), 'sandbox' => env('APPLE_STORE_SANDBOX', true), 'root_ca' => env('APPLE_STORE_ROOT_CA')],
    'google' => ['package_name' => env('GOOGLE_PLAY_PACKAGE_NAME'), 'credentials' => env('GOOGLE_PLAY_SERVICE_ACCOUNT_JSON'), 'webhook_audience' => env('GOOGLE_PLAY_WEBHOOK_AUDIENCE'), 'webhook_email' => env('GOOGLE_PLAY_WEBHOOK_EMAIL')],
    'student' => ['enabled' => env('STUDENT_REVIEW_ENABLED', false), 'eligibility_months' => 12, 'evidence_retention_days' => 14],
    'emergency_email' => ['enabled' => env('EMERGENCY_EMAIL_ENABLED', false), 'daily_limit' => (int) env('EMERGENCY_EMAIL_DAILY_LIMIT', 0), 'cooldown_seconds' => 300, 'per_account_daily_limit' => 10],
];
