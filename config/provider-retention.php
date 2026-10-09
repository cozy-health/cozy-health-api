<?php

return [
    // Record the signed policy version before enabling any destructive run.
    'approval_reference' => env('PROVIDER_RETENTION_APPROVAL', ''),
    // Two years is a proposal only; no active default destruction window.
    'access_log_days' => env('PROVIDER_ACCESS_LOG_DAYS'),
    // Comma-separated provider IDs. A global hold disables all deletion.
    'global_hold' => env('PROVIDER_RETENTION_GLOBAL_HOLD', false),
    'held_provider_ids' => env('PROVIDER_RETENTION_HELD_PROVIDER_IDS', ''),
];
