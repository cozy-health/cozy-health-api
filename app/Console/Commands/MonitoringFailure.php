<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

final class MonitoringFailure extends Command
{
    protected $signature = 'monitoring:development-failure';
    protected $description = 'Throw a content-free development exception to test diagnostic delivery';

    public function handle(): int
    {
        if (!in_array(app()->environment(), ['local', 'development'], true)
            || !config('monitoring.sentry_development_enabled') || !config('sentry.dsn')) {
            $this->error('Requires explicitly enabled development Sentry configuration. No fault triggered.');
            return self::FAILURE;
        }
        throw new \RuntimeException('[DEV TEST] Controlled monitoring failure');
    }
}
