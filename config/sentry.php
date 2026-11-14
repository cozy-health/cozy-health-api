<?php

$dsn = env('SENTRY_LARAVEL_DSN');
$parsed = is_string($dsn) ? parse_url($dsn) : false;
$production = config('app.env') === 'production';
$expectedHost = $production ? env('SENTRY_ALLOWED_HOST') : 'o4512334056128512.ingest.de.sentry.io';
$expectedProject = $production ? env('SENTRY_PROJECT_ID') : '4512362654990416';
$enabled = config('monitoring.'.($production ? 'sentry_production_enabled' : 'sentry_development_enabled'))
    && is_string($expectedHost) && $expectedHost !== ''
    && is_string($expectedProject) && $expectedProject !== ''
    && is_array($parsed) && ($parsed['scheme'] ?? '') === 'https'
    && ($parsed['host'] ?? '') === $expectedHost
    && ($parsed['path'] ?? '') === '/'.$expectedProject
    && !empty($parsed['user']) && !isset($parsed['pass']) && !isset($parsed['query'])
    && !isset($parsed['port']) && !isset($parsed['fragment']);

return [
    'dsn' => $enabled ? $dsn : null,
    'environment' => $production ? 'production' : 'development',
    'release' => env('SENTRY_RELEASE'),
    'send_default_pii' => false,
    'attach_stacktrace' => false,
    'max_request_body_size' => 'never',
    'max_breadcrumbs' => 0,
    'context_lines' => 0,
    'traces_sample_rate' => 0.0,
    'profiles_sample_rate' => 0.0,
    'enable_logs' => false,
    'before_send' => [\App\Support\Monitoring\SentryPrivacy::class, 'beforeSend'],
    'breadcrumbs' => array_fill_keys(['logs', 'cache', 'livewire', 'sql_queries', 'sql_bindings', 'queue_info', 'command_info', 'http_client_requests', 'notifications'], false),
    'tracing' => ['default_integrations' => false, 'queue_job_transactions' => false],
];
