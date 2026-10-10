<?php

return [
    // Phase 16 implements contracts, not permission to launch community.
    // Legal review, a staffed moderation team and escalation processes are
    // required by the build plan before enabling this on a public deployment.
    'release_ready' => filter_var(env('COMMUNITY_RELEASE_READY', false), FILTER_VALIDATE_BOOLEAN),
    'guidelines_version' => env('COMMUNITY_GUIDELINES_VERSION'),
];
