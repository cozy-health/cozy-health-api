<?php

return [
    // Fail closed until credentials and device acceptance have been verified.
    'enabled' => env('PUSH_ENABLED', false),
    'ios_enabled' => env('PUSH_IOS_ENABLED', false),
    'project_id' => env('FIREBASE_PROJECT_ID'),
    'credentials' => env('FIREBASE_CREDENTIALS_PATH'),
    'credentials_json' => env('FIREBASE_CREDENTIALS_JSON'),
];
