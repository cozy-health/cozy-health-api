<?php

namespace App\Console\Commands;

use App\Services\DeletionLedger;
use Illuminate\Console\Command;

final class PruneDeletionRecords extends Command
{
    protected $signature = 'privacy:deletions-prune {--execute : Remove resolved records older than fourteen days}';
    protected $description = 'Preview or remove expired resolved development deletion receipts';

    public function handle(DeletionLedger $ledger): int
    {
        try {
            $count = $ledger->prune((bool) $this->option('execute'));
            $this->info($count.' expired resolved deletion records '.($this->option('execute') ? 'removed.' : 'eligible; dry run only.'));
            return self::SUCCESS;
        } catch (\Throwable) {
            $this->error('Deletion receipt pruning failed; no success is assumed. Check environment, storage and receipt integrity.');
            return self::FAILURE;
        }
    }
}
