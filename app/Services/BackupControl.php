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
        $this->store()->lock('maintenance-transition', 10)->block(5, function () use ($name): void {
            $operatorMaintenance = app()->isDownForMaintenance()
                && ! isset(app()->maintenanceMode()->data()['backup_owner'])
                && ! $this->store()->has('lease');
            $this->commands[$name][] = $this->beginActivity($name === 'schedule:run' || $operatorMaintenance);
        });
    }
    public function commandFinished(?string $name): void
    {
        if ($name && ! empty($this->commands[$name])) $this->endActivity(array_pop($this->commands[$name]));
    }
    public function acquire(string $id, ?string $operatorWindow = null): float
    {
        $this->assertReady();
        return $this->store()->lock('maintenance-transition', 10)->block(5, fn () => DB::transaction(function () use ($id, $operatorWindow) {
            if ($this->store()->has('lease')) throw new RuntimeException('An existing backup lease must not be replaced.');
            $seconds = (float) config('backup.window_seconds');
            if (! is_finite($seconds) || $seconds <= 0) throw new RuntimeException('Invalid backup duration.');
            $deadline = microtime(true) + min(180, $seconds);
            $lease = ['id' => $id, 'deadline' => $deadline, 'deployment' => getenv('RAILWAY_DEPLOYMENT_ID') ?: 'testing'];
            if ($operatorWindow !== null) {
                $operatorDeadline = $this->operatorDeadline($operatorWindow);
                if ($operatorDeadline === null) throw new RuntimeException('A matching live operator maintenance window is required.');
                $deadline = min($deadline, $operatorDeadline);
                $lease['deadline'] = $deadline;
                $lease['operator_window'] = $operatorWindow;
                $lease['operator_deadline'] = $operatorDeadline;
            } elseif (app()->isDownForMaintenance()) {
                throw new RuntimeException('Existing maintenance must not be replaced.');
            }
            if (! $this->store()->forever('lease', $lease)) throw new RuntimeException('Backup lease could not be saved.');
            if ($operatorWindow === null) {
                app()->maintenanceMode()->activate(['backup_owner' => $id, 'retry' => 180, 'time' => time()]);
            }
            return $deadline;
        }));
    }
    private function operatorDeadline(string $window): ?float
    {
        if (! Str::isUuid($window) || ! app()->isDownForMaintenance()) return null;
        $payload = app()->maintenanceMode()->data();
        $owner = $payload['operator_window'] ?? null;
        $deadline = $payload['operator_deadline'] ?? null;
        $started = $payload['time'] ?? null;
        if (! is_string($owner) || ! hash_equals($owner, $window) || isset($payload['backup_owner'])
            || ! is_numeric($deadline) || ! is_numeric($started)) return null;
        $deadline = (float) $deadline;
        $started = (float) $started;
        if (! is_finite($deadline) || ! is_finite($started) || $started > microtime(true)
            || $deadline <= microtime(true) || $deadline > $started + 900) return null;
        return $deadline;
    }
    public function owns(string $id): bool
    {
        $lease = $this->store()->get('lease');
        if (! is_array($lease) || ($lease['id'] ?? null) !== $id
            || ! is_numeric($lease['deadline'] ?? null) || ! is_finite((float) $lease['deadline'])
            || $lease['deadline'] <= microtime(true) || ! app()->isDownForMaintenance()) return false;
        if (isset($lease['operator_window'])) {
            if (! is_string($lease['operator_window'])) return false;
            $deadline = $this->operatorDeadline($lease['operator_window']);
            return $deadline !== null && $deadline === ($lease['operator_deadline'] ?? null);
        }
        $payload = app()->maintenanceMode()->data();
        return ($payload['backup_owner'] ?? null) === $id && ! isset($payload['operator_window']);
    }
    public function release(string $id): void
    {
        $this->store()->lock('maintenance-transition', 10)->block(5, fn () => DB::transaction(function () use ($id) {
            $lease = $this->store()->get('lease');
            if (! isset($lease['operator_window']) && app()->isDownForMaintenance()) {
                $payload = app()->maintenanceMode()->data();
                if (($payload['backup_owner'] ?? null) === $id && ! isset($payload['operator_window'])) {
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
        Log::error('Overdue backup lease released. Preserve the previous verified recovery copy.');
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
