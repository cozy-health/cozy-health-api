<?php

return [
    // Explicit assignment; moderation does not confer these privileges.
    'support_reviewers' => array_values(array_filter(array_map('intval', explode(',', (string) env('SUPPORT_REVIEWER_IDS', ''))))),
    'privacy_reviewers' => array_values(array_filter(array_map('intval', explode(',', (string) env('PRIVACY_REVIEWER_IDS', ''))))),
];
