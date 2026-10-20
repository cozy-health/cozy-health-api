<?php
return [
    'android_package' => env('QUIZ_ANDROID_PACKAGE', 'com.example.cozy_health'),
    'android_fingerprints' => array_values(array_filter(explode(',', env('QUIZ_ANDROID_FINGERPRINTS', '')))),
    'ios_app_id' => env('QUIZ_IOS_APP_ID'),
    'android_store_url' => env('QUIZ_ANDROID_STORE_URL'),
    'ios_store_url' => env('QUIZ_IOS_STORE_URL'),
];
