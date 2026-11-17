<?php

return [
    // Enable only after the development storage-usage estimate is approved.
    'enabled' => env('DELETION_LEDGER_ENABLED', false),
    'activated_at' => env('DELETION_LEDGER_ACTIVATED_AT'),
    'disk' => 'local',
    'prefix' => 'deletion-ledger/v1',
    'retention_days' => 14,
    'backup_days' => 7,
];
