<?php

return [
    // Record the approved policy version before enabling any destructive run.
    'approval_reference' => env('PROVIDER_RETENTION_APPROVAL', ''),
    // Approved 2026-10-21: routine access records have a maximum two-year term.
    // Execution still requires the approval reference and respects legal holds.
    'access_log_days' => env('PROVIDER_ACCESS_LOG_DAYS', 730),
    // Comma-separated provider IDs. A global hold disables all deletion.
    'global_hold' => env('PROVIDER_RETENTION_GLOBAL_HOLD', false),
    'held_provider_ids' => env('PROVIDER_RETENTION_HELD_PROVIDER_IDS', ''),
];
