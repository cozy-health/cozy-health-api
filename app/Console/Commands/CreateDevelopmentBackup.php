<?php
namespace App\Console\Commands;

use App\Services\DevelopmentBackup;
use App\Services\DevelopmentBackupControl;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

final class CreateDevelopmentBackup extends Command
{
    protected $signature = 'development:backup {--execute : Create one verified encrypted recovery copy} {--verify= : Verify an existing backup UUID without maintenance}';
    protected $description = 'Preview, create or verify approved development-only daily backups';
    public function handle(DevelopmentBackup $backup, DevelopmentBackupControl $control): int
    {
        try {
            $control->assertReady();
            if ($this->option('execute') && $this->option('verify')) throw new \RuntimeException('Choose create or verification.');
            if ($this->option('verify')) $result = $backup->verify($this->option('verify'));
            elseif ($this->option('execute')) $result = $backup->create();
            else { $this->info('Dry run only: daily 03:00 Africa/Lagos, seven-day retention, three-minute maintenance budget; no backup created.'); return self::SUCCESS; }
            $this->line(json_encode($result, JSON_THROW_ON_ERROR));
            return self::SUCCESS;
        } catch (\Throwable $error) {
            Log::error('Development backup failed; no complete new recovery copy is assumed.', ['exception_type' => get_class($error)]);
            $this->error('Backup refused or incomplete. Review environment, client/TLS, in-flight work, lease, size limits and storage. Preserve old backups.');
            return self::FAILURE;
        }
    }
}
