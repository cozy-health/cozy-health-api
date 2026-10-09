<?php
return [
    'default' => env('DB_CONNECTION', 'mysql'),
    'connections' => [
        'mysql' => [
            'driver' => 'mysql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'cozy_health'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'require_tls' => env('DB_REQUIRE_TLS', ! in_array(env('APP_ENV', 'production'), ['local', 'testing'], true)),
            'options' => [
                PDO::ATTR_TIMEOUT => (int) env('DB_CONNECTION_TIMEOUT', 5),
                PDO::ATTR_PERSISTENT => false,
            ] + (env('MYSQL_SSL_CA') ? [
                PDO::MYSQL_ATTR_SSL_CA => env('MYSQL_SSL_CA'),
                PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => true,
            ] : []),
        ],
    ],
    'migrations' => ['table' => 'migrations', 'update_date_on_publish' => true],
];
