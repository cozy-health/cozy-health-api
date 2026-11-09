<?php
return [
    'enabled' => env('DEVELOPMENT_BACKUP_ENABLED', false),
    'scheduled' => env('DEVELOPMENT_BACKUP_SCHEDULED', false),
    'disk' => 'profile_avatars', 'prefix' => 'private/development-backups/v1',
    'control_prefix' => 'cozy_development_backup_', 'daily_at' => '02:00',
    'retention_days' => 7, 'window_seconds' => 180, 'drain_seconds' => 95,
    'max_archive_bytes' => 25 * 1024 * 1024, 'max_plain_bytes' => 100 * 1024 * 1024,
    'max_files' => 10000, 'dump_binary' => '/usr/bin/mysqldump',
];
