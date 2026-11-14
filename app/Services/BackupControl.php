<?php
namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

final class BackupControl
{
    private array $commands = [];
    public function enabled(): bool { return (bool) config('backup.enabled'); }
    public function assertReady(): void
    {
        if (! $this->enabled()) throw new RuntimeException('Backup activation is required.');
        app(BackupEnvironment::class)->assertApproved(maintenanceRequired: true);
        $store = config('backup.cache_store');
        if (config('app.maintenance.driver') !== 'cache' || config('app.maintenance.store') !== $store) throw new RuntimeException('Shared backup maintenance is required.');
        if (config('cache.stores.'.$store.'.driver') !== 'database' || config('cache.stores.'.$store.'.prefix') !== config('backup.control_prefix')) throw new RuntimeException('Shared backup control store is invalid.');
        if (! app()->environment('testing') && (! config('database.connections.mysql.require_tls') || ! (config('database.connections.mysql.options')[\PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] ?? false))) throw new RuntimeException('Verified database TLS is required.');
    }
    public function store() { return Cache::store(config('backup.cache_store')); }
    public function beginActivity(bool $allowMaintenance = false): string
    {
        $this->assertReady();
        $id = (string) Str::uuid();
        if (! $this->store()->forever('activity:'.$id, ['started_at' => time()])) throw new RuntimeException('Activity tracking failed.');
        if (! $allowMaintenance && app()->isDownForMaintenance()) { $this->endActivity($id); abort(503, 'Maintenance is in progress.'); }
        return $id;
    }
    public function endActivity(string $id): void { $this->store()->forget('activity:'.$id); }
    public function activeCount(): int
    {
        // A crashed writer marker requires review; expiry is not evidence of completion.
        $prefix = config('backup.control_prefix').'activity:';
        return DB::table('cache')->whereRaw('substr(`key`,1,?) = ?', [strlen($prefix), $prefix])->count();
    }
    public function commandStarting(?string $name): void
    {
        if (! $this->enabled() || ! $name || in_array($name, ['backup:create', 'backup:watchdog', 'development:backup', 'development:backup-watchdog', 'queue:work', 'schedule:work', 'queue:restart', 'config:clear', 'route:clear', 'view:clear', 'storage:link', 'up', 'down', 'list', 'help'], true)) return;
        $operatorMaintenance = app()->isDownForMaintenance() && ! isset(app()->maintenanceMode()->data()['backup_owner']);
        $this->commands[$name][] = $this->beginActivity($name === 'schedule:run' || $operatorMaintenance);
    }
    public function commandFinished(?string $name): void
    {
        if ($name && ! empty($this->commands[$name])) $this->endActivity(array_pop($this->commands[$name]));
    }
    public function acquire(string $id): float
    {
        $this->assertReady();
        return $this->store()->lock('maintenance-transition', 10)->block(5, fn () => DB::transaction(function () use ($id) {
            if (app()->isDownForMaintenance() || $this->store()->has('lease')) throw new RuntimeException('Existing maintenance or backup lease must not be replaced.');
            $deadline = microtime(true) + config('backup.window_seconds');
            $this->store()->forever('lease', ['id' => $id, 'deadline' => $deadline, 'deployment' => getenv('RAILWAY_DEPLOYMENT_ID') ?: 'testing']);
            app()->maintenanceMode()->activate(['backup_owner' => $id, 'retry' => 180, 'time' => time()]);
            return $deadline;
        }));
    }
    public function owns(string $id): bool
    {
        $lease = $this->store()->get('lease');
        return is_array($lease) && ($lease['id'] ?? null) === $id && ($lease['deadline'] ?? 0) > microtime(true) && app()->isDownForMaintenance() && (app()->maintenanceMode()->data()['backup_owner'] ?? null) === $id;
    }
    public function release(string $id): void
    {
        $this->store()->lock('maintenance-transition', 10)->block(5, fn () => DB::transaction(function () use ($id) {
            if (app()->isDownForMaintenance()) {
                $payload = app()->maintenanceMode()->data();
                if (($payload['backup_owner'] ?? null) === $id) {
                    $comparison = DB::connection()->getDriverName() === 'mysql' ? 'BINARY `value` = ?' : 'CAST(`value` AS BLOB) = CAST(? AS BLOB)';
                    DB::table('cache')->where('key', config('backup.control_prefix').'illuminate:foundation:down')->whereRaw($comparison, [serialize($payload)])->delete();
                }
            }
            if (($this->store()->get('lease')['id'] ?? null) === $id) $this->store()->forget('lease');
        }));
    }
    public function watchdog(): bool
    {
        $this->assertReady();
        $lease = $this->store()->get('lease');
        if (! is_array($lease) || ($lease['deadline'] ?? PHP_FLOAT_MAX) > microtime(true)) return false;
        $this->release($lease['id']);
        Log::error('Overdue backup maintenance released; incomplete backup is not restorable.');
        return true;
    }
    public function waitForDrain(float $deadline): void
    {
        $until = min($deadline - 60, microtime(true) + config('backup.drain_seconds'));
        do {
            if ($this->activeCount() === 0 && DB::table('jobs')->whereNotNull('reserved_at')->count() === 0) return;
            usleep(200000);
        } while (microtime(true) < $until);
        throw new RuntimeException('In-flight work did not drain within the backup window.');
    }
}
