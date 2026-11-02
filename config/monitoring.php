<?php

$local = env('APP_ENV') === 'local' && !env('RAILWAY_ENVIRONMENT_ID');
$development = env('APP_ENV') === 'development'
    && env('APP_URL') === 'https://api-dev.cozyhealth.org'
    && env('RAILWAY_PROJECT_ID') === '33f9a938-49e3-42c5-83c7-633ee85760fa'
    && env('RAILWAY_ENVIRONMENT_ID') === '56b85cc3-e80b-4cb9-a0d4-b47abe8586f6';

return [
    'sentry_development_enabled' => (bool) env('SENTRY_DEVELOPMENT_ENABLED', false)
        && ($local || $development),
];
