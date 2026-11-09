<?php
namespace App\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Process\Process;

class DevelopmentDatabaseDump
{
    private function quoted(string $value): string
    {
        if (str_contains($value, "\0")) throw new RuntimeException('Invalid database credential encoding.');
        return '"'.str_replace(['\\', '"', "\n", "\r"], ['\\\\', '\\"', '\\n', '\\r'], $value).'"';
    }

    public function export(BackupCipher $writer, string $work, float $deadline, callable $checkpoint): void
    {
        $db = DB::connection();
        if ($db->getDriverName() !== 'mysql') throw new RuntimeException('MySQL is required for native exports.');
        $name = $db->getDatabaseName();
        if (! preg_match('/^[A-Za-z0-9_]+$/D', $name)) throw new RuntimeException('Invalid database name.');
        $unsupported = (array) $db->selectOne('SELECT (SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = ?)+(SELECT COUNT(*) FROM information_schema.EVENTS WHERE EVENT_SCHEMA = ?)+(SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ?)+(SELECT COUNT(*) FROM information_schema.VIEWS WHERE TABLE_SCHEMA = ?)+(SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND ENGINE <> \'InnoDB\') AS n', array_fill(0, 5, $name));
        if ((int) $unsupported['n'] !== 0) throw new RuntimeException('Unsupported stored objects require an explicit backup design.');
        $cfg = $db->getConfig();
        $options = $cfg['options'] ?? [];
        $config = "[client]\n";
        foreach (['user' => $cfg['username'], 'password' => $cfg['password'], 'host' => $cfg['host'], 'port' => (string) $cfg['port']] as $key => $value) $config .= $key.'='.$this->quoted((string) $value)."\n";
        if (! app()->environment('testing') || $cfg['require_tls']) {
            $ca = $options[\PDO::MYSQL_ATTR_SSL_CA] ?? null;
            if (! is_string($ca) || ! is_readable($ca) || ! ($options[\PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] ?? false)) throw new RuntimeException('Verified native-client TLS configuration is missing.');
            $config .= 'ssl-mode=VERIFY_IDENTITY'."\n".'ssl-ca='.$this->quoted($ca)."\n";
        } else $config .= "ssl-mode=DISABLED\n"; // Isolated hosted test database only.
        $path = $work.'/client.cnf';
        if (file_put_contents($path, $config) === false || ! chmod($path, 0600)) throw new RuntimeException('Private client configuration failed.');
        try {
            $base = [config('development-backup.dump_binary'), '--defaults-extra-file='.$path, '--protocol=TCP', '--default-character-set=utf8mb4', '--single-transaction', '--quick', '--skip-lock-tables', '--no-tablespaces', '--set-gtid-purged=OFF', '--column-statistics=0', '--hex-blob', '--skip-comments', '--skip-dump-date', '--skip-routines', '--skip-events', '--skip-triggers', '--skip-add-locks', '--skip-disable-keys'];
            // Preserve ephemeral-table schemas, but never restore maintenance leases,
            // cache tokens or request markers from the snapshot.
            $this->run([...$base, '--ignore-table='.$name.'.cache', '--ignore-table='.$name.'.cache_locks', $name], $writer, $deadline, $checkpoint);
            $this->run([...$base, '--no-data', $name, 'cache', 'cache_locks'], $writer, $deadline, $checkpoint);
            $restore = serialize(['development_restore' => true, 'retry' => 180, 'time' => time()]);
            $key = config('development-backup.control_prefix').'illuminate:foundation:down';
            $writer->append("\nINSERT INTO `cache` (`key`,`value`,`expiration`) VALUES (".$db->getPdo()->quote($key).','.$db->getPdo()->quote($restore).",2147483647);\n");
        } finally { if (is_file($path)) unlink($path); }
    }

    private function run(array $arguments, BackupCipher $writer, float $deadline, callable $checkpoint): void
    {
        $checkpoint();
        $timeout = min(60, $deadline - microtime(true) - 15);
        if ($timeout < 1) throw new RuntimeException('Backup export window exhausted.');
        $process = new Process($arguments, null, null, null, $timeout);
        try {
            $process->run(function ($type, $bytes) use ($writer, $process, $checkpoint) {
                $checkpoint();
                if ($type === Process::OUT) $writer->append($bytes);
                // Do not retain plaintext SQL in Symfony's output buffers or logs.
                $process->clearOutput(); $process->clearErrorOutput();
            });
        } finally { if ($process->isRunning()) $process->stop(1); }
        if (! $process->isSuccessful()) throw new RuntimeException('Native export failed with exit code '.$process->getExitCode().'.');
    }
}
