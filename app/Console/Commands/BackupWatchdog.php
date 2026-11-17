<?php
namespace App\Console\Commands;

use App\Services\BackupControl;
use Illuminate\Console\Command;

final class BackupWatchdog extends Command
{
    protected $signature = 'backup:watchdog';
    protected $aliases = ['development:backup-watchdog'];
    protected $description = 'Release only overdue backup-owned maintenance';
    public function handle(BackupControl $control): int
    {
        try { $this->info($control->watchdog() ? 'Overdue backup maintenance released.' : 'No overdue backup maintenance.'); return self::SUCCESS; }
        catch (\Throwable) { $this->error('Backup watchdog failed; operator review required.'); return self::FAILURE; }
    }
}
