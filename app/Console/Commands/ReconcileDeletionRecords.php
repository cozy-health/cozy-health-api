<?php

namespace App\Console\Commands;

use App\Services\DeletionLedger;
use Illuminate\Console\Command;

final class ReconcileDeletionRecords extends Command
{
    protected $signature = 'privacy:deletions-reconcile {operation : Pending operation UUID from private storage} {outcome : completed or aborted} {--original-database : Attest this is the original authoritative database, not a restored snapshot} {--execute : Persist the verified outcome}';
    protected $description = 'Reconcile an interrupted deletion against the original development database';

    public function handle(DeletionLedger $ledger): int
    {
        try {
            if (! $this->option('original-database')) throw new \RuntimeException('Original database attestation is required.');
            $ledger->reconcile($this->argument('operation'), $this->argument('outcome'), (bool) $this->option('execute'));
            $this->info($this->option('execute') ? 'Pending deletion outcome reconciled.' : 'Original database supports this outcome; dry run only.');
            return self::SUCCESS;
        } catch (\Throwable) {
            $this->error('Reconciliation refused. Verify original database identity, receipt integrity and actual account deletion outcome; do not guess from a restored snapshot.');
            return self::FAILURE;
        }
    }
}
