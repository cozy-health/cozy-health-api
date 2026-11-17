<?php

return [
    'project_id' => env('RAILWAY_PROJECT_ID'),
    'environment_id' => env('RAILWAY_ENVIRONMENT_ID'),
    'allowed_recipients' => array_values(array_filter(array_map('trim', explode(',', (string) env('DEVELOPMENT_MAIL_ALLOWED_RECIPIENTS', ''))))),
    'test_recipient' => env('DEVELOPMENT_MAIL_TEST_RECIPIENT'),
];
