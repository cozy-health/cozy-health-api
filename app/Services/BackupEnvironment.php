<?php

namespace App\Services;

use RuntimeException;

final class BackupEnvironment
{
    public function name(): string
    {
        return (string) config('backup.environment');
    }

    public function assertApproved(bool $maintenanceRequired = false): void
    {
        $environment = app()->environment();
        if ($environment === 'testing') {
            return;
        }

        $expected = match ($environment) {
            'development' => ['56b85cc3-e80b-4cb9-a0d4-b47abe8586f6', 'https://api-dev.cozyhealth.org'],
            'production' => ['9ffd3fd1-ae52-4cc7-903e-35b529a151ae', 'https://api.cozyhealth.org'],
            default => throw new RuntimeException('Backup environment is unsupported.'),
        };

        if ($this->name() !== $environment
            || config('backup.project_id') !== '33f9a938-49e3-42c5-83c7-633ee85760fa'
            || config('backup.environment_id') !== $expected[0]
            || config('app.url') !== $expected[1]) {
            throw new RuntimeException('Backup deployment identity is invalid.');
        }

        if ($maintenanceRequired && $environment === 'production' && config('backup.maintenance_approved') !== true) {
            throw new RuntimeException('Backup maintenance requires explicit activation approval.');
        }
    }
}
