<?php

return [
    'enabled' => filter_var(env('DEVELOPMENT_TEST_ACCESS_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
    'railway_project_id' => env('RAILWAY_PROJECT_ID'),
    'railway_environment_id' => env('RAILWAY_ENVIRONMENT_ID'),
];
