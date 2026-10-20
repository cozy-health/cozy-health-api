<?php

namespace App\Console\Commands;

use App\Services\Provider\EncryptedReportStorage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class EncryptProviderReports extends Command
{
    protected $signature = 'reports:encrypt';
    protected $description = 'Verify and migrate existing local provider PDFs to authenticated AES-256 ciphertext';

    public function handle(EncryptedReportStorage $storage): int
    {
        $migrated = 0;
        $verified = 0;
        $failed = 0;
        foreach (Storage::disk('local')->allFiles('provider-reports') as $path) {
            if (! str_ends_with($path, '.pdf')) {
                continue;
            }
            try {
                $storage->migrate($path) ? $migrated++ : $verified++;
            } catch (\Throwable) {
                // Do not log PHI, paths or exception messages.
                $failed++;
            }
        }
        $this->info("Migrated: {$migrated}; verified: {$verified}; failed: {$failed}.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
