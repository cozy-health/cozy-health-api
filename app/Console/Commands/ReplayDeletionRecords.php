<?php

namespace App\Console\Commands;

use App\Services\DeletionLedger;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

final class ReplayDeletionRecords extends Command
{
    protected $signature = 'privacy:deletions-replay {--snapshot= : Original backup timestamp in ISO 8601 with timezone} {--execute : Apply deletions to an isolated restore}';
    protected $description = 'Validate or replay encrypted deletion receipts before reopening an isolated development restore';

    public function handle(DeletionLedger $ledger): int
    {
        try {
            $value = $this->option('snapshot');
            if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/D', $value)) {
                throw new \RuntimeException('Supply the backup timestamp in ISO 8601 with timezone.');
            }
            if ($this->option('execute') && ! app()->isDownForMaintenance()) {
                throw new \RuntimeException('Execution requires an isolated restore in maintenance mode with external workers stopped.');
            }
            $count = $ledger->replay(Carbon::parse($value), (bool) $this->option('execute'));
            $this->info($count.' completed deletion records '.($this->option('execute') ? 'replayed; verify private-file cleanup before reopening.' : 'validated; dry run only.'));
            return self::SUCCESS;
        } catch (\Throwable) {
            // Do not expose decrypted receipts, file paths, credentials or account IDs.
            $this->error('Replay refused or incomplete. Check snapshot age, environment, maintenance state, receipt integrity, unresolved operations and account identity. Keep the restore closed.');
            return self::FAILURE;
        }
    }
}
