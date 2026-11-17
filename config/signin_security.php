<?php

return [
    // Enable new enrollment only after compatible mobile/provider clients are deployed.
    // Pausing rollout preserves required protection and recovery for existing participants.
    'enabled' => (bool) env('SIGNIN_SECURITY_ENABLED', false),
];
