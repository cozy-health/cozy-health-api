<?php

return [
    'documents' => collect([
        'tos' => ['Terms of Service', true],
        'privacy_policy' => ['Privacy Policy', true],
        'health_data' => ['Health Data Consent', true],
        'marketing' => ['Marketing emails', false],
        'ugc_guidelines' => ['Community Guidelines', false],
    ])->map(fn ($value) => ['title' => $value[0], 'required' => $value[1], 'version' => 'draft-0.1', 'published' => false, 'content' => null])->all(),
    'provider_documents' => [
        'tos' => ['title' => 'Provider Terms of Service', 'required' => true, 'version' => 'draft-0.1', 'published' => false, 'content' => null],
        'privacy_policy' => ['title' => 'Provider Privacy Policy', 'required' => true, 'version' => 'draft-0.1', 'published' => false, 'content' => null],
    ],
];
