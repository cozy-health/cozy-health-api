<?php

return [
    // Enable enforcement only after the compatible mobile release is available.
    'reviewers' => env('GUARDIAN_REVIEWER_IDS', ''),
    'enabled' => false,
    'invitations_enabled' => false,
    'health_data_ready' => false,
    'approved_countries' => array_values(array_filter(explode(',', env('MINOR_APPROVED_COUNTRIES', '')))),
    'assurance_approval' => env('GUARDIAN_ASSURANCE_APPROVAL'),
    'correction_approval' => env('AGE_CORRECTION_PROCEDURE_APPROVAL'),
    'retention_approval' => env('GUARDIAN_RETENTION_APPROVAL'),
    'record_days' => (int) env('GUARDIAN_RECORD_DAYS', 0),
    'website_url' => env('GUARDIAN_WEBSITE_URL', 'https://cozyhealth.org'),
];
