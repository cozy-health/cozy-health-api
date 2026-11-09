<?php
namespace App\Console\Commands;

use App\Services\DevelopmentBackupControl;
use Illuminate\Console\Command;

final class DevelopmentBackupWatchdog extends Command
{
    protected $signature = 'development:backup-watchdog';
    protected $description = 'Release only overdue backup-owned development maintenance';
    public function handle(DevelopmentBackupControl $control): int
    {
        try { $this->info($control->watchdog() ? 'Overdue backup maintenance released.' : 'No overdue backup maintenance.'); return self::SUCCESS; }
        catch (\Throwable) { $this->error('Backup watchdog failed; operator review required.'); return self::FAILURE; }
    }
}
