<?php
// Runs only against the disposable CI database, never a Railway environment.
try {
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (! app()->environment('testing') || getenv('DB_DATABASE') !== 'cozy_release_test' || getenv('DB_HOST') !== '127.0.0.1' || getenv('RAILWAY_PROJECT_ID')) throw new RuntimeException('Isolated CI database required.');
config(['app.key' => 'base64:'.base64_encode(random_bytes(32)), 'development-backup.enabled' => true,
    'app.maintenance.driver' => 'cache', 'app.maintenance.store' => 'development_backup',
    'deletion-ledger.enabled' => true, 'deletion-ledger.activated_at' => now()->subHour()->toIso8601String()]);
$app->forgetInstance('encrypter');
$disk = Illuminate\Support\Facades\Storage::build(['driver' => 'local', 'root' => '/tmp/cozy-backup-native-'.bin2hex(random_bytes(8)), 'throw' => true]);
Illuminate\Support\Facades\Storage::set('profile_avatars', $disk);
$disk->put('private/synthetic.txt', 'native-backup-fixture');
$db = Illuminate\Support\Facades\DB::connection();
$source = 'cozy_backup_source_'.bin2hex(random_bytes(6));
if (! preg_match('/^cozy_backup_source_[a-f0-9]{12}$/D', $source)) throw new RuntimeException('Invalid task source database.');
$admin = $db;
$admin->statement('CREATE DATABASE `'.$source.'`');
config(['development-backup.enabled' => false, 'app.maintenance.driver' => 'file',
    'cache.stores.development_backup.connection' => 'backup_fixture', 'cache.stores.development_backup.lock_connection' => 'backup_fixture',
    // getConfig() includes the old name. Laravel preserves it unless explicitly
    // replaced, and Migrator then switches back to the shared mysql connection.
    'database.connections.backup_fixture' => array_replace($db->getConfig(), ['name' => 'backup_fixture', 'database' => $source, 'url' => null])]);
Illuminate\Support\Facades\DB::setDefaultConnection('backup_fixture');
// Bootstrap may already have resolved Schema against the shared CI database.
// Switching the manager default does not invalidate that facade's cached builder.
Illuminate\Support\Facades\Schema::clearResolvedInstance('db.schema');
if (Illuminate\Support\Facades\Schema::getConnection()->getDatabaseName() !== $source || Illuminate\Support\Facades\Schema::getConnection()->getName() !== 'backup_fixture') throw new RuntimeException('Native fixture schema connection is not isolated.');
if (Illuminate\Support\Facades\Artisan::call('migrate', ['--database' => 'backup_fixture', '--force' => true]) !== 0) throw new RuntimeException('Native fixture migrations failed.');
config(['development-backup.enabled' => true, 'app.maintenance.driver' => 'cache']);
$db = Illuminate\Support\Facades\DB::connection();
$db->statement('CREATE TABLE backup_native_probe (id BIGINT PRIMARY KEY, bytes_value BLOB, json_value JSON) ENGINE=InnoDB');
$bytes = "fixture\0\xff\x01";
$db->insert('INSERT INTO backup_native_probe VALUES (1,?,?)', [$bytes, json_encode(['message' => 'synthetic unicode ✓'])]);
$restore = 'cozy_backup_fixture_'.bin2hex(random_bytes(6));
if (! preg_match('/^cozy_backup_fixture_[a-f0-9]{12}$/D', $restore)) throw new RuntimeException('Invalid task database.');
$created = false;
$options = '/tmp/cozy-backup-client-'.bin2hex(random_bytes(8)).'.cnf';
try {
    $result = app(App\Services\DevelopmentBackup::class)->create();
    $manifest = json_decode(Illuminate\Support\Facades\Crypt::decryptString($disk->get('private/development-backups/v1/'.$result['id'].'/manifest.enc')), true, flags: JSON_THROW_ON_ERROR);
    $sql = '';
    $input = $disk->readStream($manifest['database']['object']);
    try { App\Services\BackupCipher::verify($input, base64_decode($manifest['key']), $manifest['database'], function ($part) use (&$sql) { $sql .= $part; }); }
    finally { fclose($input); }
    if (preg_match('/^(USE |CREATE DATABASE|DROP DATABASE)/mi', $sql)) throw new RuntimeException('Unexpected database-switch statement.');
    $db->statement('CREATE DATABASE `'.$restore.'`'); $created = true;
    file_put_contents($options, "[client]\nuser=root\npassword=isolated-test-password\nhost=127.0.0.1\nport=3306\nssl-mode=DISABLED\n");
    chmod($options, 0600);
    $process = new Symfony\Component\Process\Process(['/usr/bin/mysql', '--defaults-extra-file='.$options, '--protocol=TCP', '--default-character-set=utf8mb4', $restore], null, null, $sql, 60);
    $process->mustRun();
    $row = (array) $db->selectOne('SELECT * FROM `'.$restore.'`.backup_native_probe WHERE id=1');
    if ($row['bytes_value'] !== $bytes || json_decode($row['json_value'], true)['message'] !== 'synthetic unicode ✓') throw new RuntimeException('Native roundtrip changed binary/JSON values.');
    $maintenance = (array) $db->selectOne('SELECT `value` FROM `'.$restore.'`.cache WHERE `key` = ?', [config('development-backup.control_prefix').'illuminate:foundation:down']);
    if (! (unserialize($maintenance['value'], ['allowed_classes' => false])['development_restore'] ?? false)) throw new RuntimeException('Restored copy did not stay in maintenance.');
    if (app()->isDownForMaintenance()) throw new RuntimeException('Original test application was left in maintenance.');
    echo "Native MySQL encrypted backup, binary/JSON import and closed-restore marker verified.\n";
} finally {
    if (is_file($options)) unlink($options);
    if ($created) $db->statement('DROP DATABASE `'.$restore.'`');
    Illuminate\Support\Facades\DB::disconnect('backup_fixture');
    $admin->statement('DROP DATABASE `'.$source.'`');
}
} catch (Throwable $error) {
    // Laravel's registered exception renderer can otherwise print an error and
    // let this standalone script exit zero, incorrectly making the CI step green.
    fwrite(STDERR, 'Native backup verification failed: '.get_class($error).': '.$error->getMessage().PHP_EOL);
    exit(1);
}
