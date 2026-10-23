<?php

return [
    // Phase 16 implements contracts, not permission to launch community.
    // Legal review, a staffed moderation team and escalation processes are
    // required by the build plan before enabling this on a public deployment.
    'release_ready' => filter_var(env('COMMUNITY_RELEASE_READY', false), FILTER_VALIDATE_BOOLEAN),
    'guidelines_version' => env('COMMUNITY_GUIDELINES_VERSION'),
    // Explicit sandbox activation; cannot stand in for public launch readiness.
    'development_testing' => filter_var(env('COMMUNITY_DEVELOPMENT_TESTING_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
    'railway_project_id' => env('RAILWAY_PROJECT_ID'),
    'railway_environment_id' => env('RAILWAY_ENVIRONMENT_ID'),
];
