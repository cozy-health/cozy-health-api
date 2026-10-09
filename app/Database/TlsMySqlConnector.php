<?php

namespace App\Database;

use Illuminate\Database\Connectors\MySqlConnector;
use PDO;
use RuntimeException;

class TlsMySqlConnector extends MySqlConnector
{
    public function connect(array $config)
    {
        if ($config['require_tls'] ?? false) {
            $ca = $config['options'][PDO::MYSQL_ATTR_SSL_CA] ?? null;
            if (! is_string($ca) || ! is_readable($ca) ||
                ($config['options'][PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] ?? null) !== true) {
                // Refuse to open a connection before credentials are transmitted.
                throw new RuntimeException('MySQL requires a trusted TLS CA and certificate verification.');
            }
        }
        $connection = parent::connect($config);
        if ($config['require_tls'] ?? false) {
            $status = $connection->query("SHOW SESSION STATUS LIKE 'Ssl_cipher'")->fetch(PDO::FETCH_NUM);
            if (! is_array($status) || empty($status[1])) {
                throw new RuntimeException('MySQL connection is not using TLS.');
            }
        }

        return $connection;
    }
}
