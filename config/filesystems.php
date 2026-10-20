<?php

return [
    'audio_disk' => env('AUDIO_FILESYSTEM_DISK', 'local'),
    'audio_ffmpeg' => env('AUDIO_FFMPEG_BINARY', 'ffmpeg'),
    'audio_ffprobe' => env('AUDIO_FFPROBE_BINARY', 'ffprobe'),
    'default' => env('FILESYSTEM_DISK', 'local'),
    'disks' => [
        // Keep the disk name stable for existing audio rows and private-file paths.
        'local' => env('PRIVATE_STORAGE_DRIVER', 'local') === 's3' ? [
            'driver' => 's3',
            'key' => env('AVATAR_ACCESS_KEY_ID'),
            'secret' => env('AVATAR_SECRET_ACCESS_KEY'),
            'region' => env('AVATAR_REGION', 'auto'),
            'bucket' => env('AVATAR_BUCKET'),
            'endpoint' => env('AVATAR_ENDPOINT'),
            'use_path_style_endpoint' => env('AVATAR_USE_PATH_STYLE_ENDPOINT', false),
            'root' => 'private',
            'visibility' => 'private',
            'throw' => true,
            'http' => ['connect_timeout' => 5, 'timeout' => 30],
        ] : ['driver' => 'local', 'root' => storage_path('app/private'), 'throw' => false],
        'public' => ['driver' => 'local', 'root' => storage_path('app/public'), 'url' => env('APP_URL').'/storage', 'visibility' => 'public', 'throw' => false],
        'profile_avatars' => [
            'driver' => 's3',
            'key' => env('AVATAR_ACCESS_KEY_ID'),
            'secret' => env('AVATAR_SECRET_ACCESS_KEY'),
            'region' => env('AVATAR_REGION', 'auto'),
            'bucket' => env('AVATAR_BUCKET'),
            'endpoint' => env('AVATAR_ENDPOINT'),
            'use_path_style_endpoint' => env('AVATAR_USE_PATH_STYLE_ENDPOINT', false),
            'visibility' => 'private',
            'throw' => true,
            'http' => ['connect_timeout' => 5, 'timeout' => 15],
            'retries' => 0,
        ],
    ],
    'links' => [public_path('storage') => storage_path('app/public')],
];
