<?php

$production = env('APP_ENV') === 'production';

return [
    'enabled' => env('BACKUP_ENABLED', $production ? false : env('DEVELOPMENT_BACKUP_ENABLED', false)),
    'scheduled' => env('BACKUP_SCHEDULED', $production ? false : env('DEVELOPMENT_BACKUP_SCHEDULED', false)),
    'environment' => $production ? 'production' : 'development',
    'project_id' => env('RAILWAY_PROJECT_ID'),
    'environment_id' => env('RAILWAY_ENVIRONMENT_ID'),
    'maintenance_approved' => env('BACKUP_MAINTENANCE_APPROVED', false),
    'expected_bucket' => env('BACKUP_STORAGE_BUCKET'),
    'disk' => 'profile_avatars',
    'prefix' => $production ? 'private/backups/v1' : 'private/development-backups/v1',
    'cache_store' => $production ? 'backup_control' : 'development_backup',
    'control_prefix' => $production ? 'cozy_backup_' : 'cozy_development_backup_',
    'daily_at' => '02:00',
    'retention_days' => 7, 'window_seconds' => 180, 'drain_seconds' => 95,
    'max_archive_bytes' => 25 * 1024 * 1024, 'max_plain_bytes' => 100 * 1024 * 1024,
    'max_files' => 10000, 'dump_binary' => '/usr/bin/mysqldump',
];
