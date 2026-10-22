<?php

namespace App\Console\Commands;

use App\Models\ProviderAccessLog;
use App\Models\ProviderReport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class PruneProviderData extends Command
{
    protected $signature = 'provider:prune {--execute : Delete eligible data; otherwise preview only}
        {--orphan-reports : Also inspect unreferenced report PDFs older than 24 hours}';

    protected $description = 'Preview or apply an approved provider audit retention policy and orphan PDF cleanup';

    public function handle(): int
    {
        $days = config('provider-retention.access_log_days');
        if ($days !== null && $days !== '' && (! ctype_digit((string) $days) || (int) $days < 1 || (int) $days > 36500)) {
            $this->error('Access log days must be an integer from 1 to 36500, or unset.');
            return self::FAILURE;
        }
        $rawHolds = trim((string) config('provider-retention.held_provider_ids', ''));
        if ($rawHolds !== '' && ! preg_match('/^[1-9]\d*(\s*,\s*[1-9]\d*)*$/D', $rawHolds)) {
            $this->error('Held provider IDs must be a comma-separated list of positive integers.');
            return self::FAILURE;
        }
        $holds = $rawHolds === '' ? [] : array_map('trim', explode(',', $rawHolds));
        if (config('provider-retention.global_hold')) {
            $this->warn('Global legal hold: no data inspected or deleted.');
            return self::SUCCESS;
        }
        $execute = (bool) $this->option('execute');
        if ($execute && trim((string) config('provider-retention.approval_reference')) === '') {
            $this->error('Execution requires PROVIDER_RETENTION_APPROVAL referencing an approved policy.');
            return self::FAILURE;
        }

        // Also protect manual execution from overlapping with a scheduled run.
        $lock = Cache::lock('provider-retention-prune', 3600);
        if (! $lock->get()) {
            $this->error('Another provider prune run is active.');
            return self::FAILURE;
        }
        try {
            $logs = 0;
            if ($days !== null && $days !== '') {
                $query = ProviderAccessLog::where('created_at', '<', now()->subDays((int) $days))
                    // Use a positive list: future/unknown actions default to preservation.
                    ->whereIn('action', ['list', 'view', 'create', 'delete', 'download'])
                    ->whereIn('resource_type', ['client', 'mood', 'journal', 'quiz', 'note', 'resource', 'assignment', 'report'])
                    ->whereNotNull('provider_id')->whereNotIn('provider_id', $holds);
                $query->select('id')->chunkById(500, function ($rows) use ($execute, &$logs) {
                    $logs += $execute ? ProviderAccessLog::whereIn('id', $rows->pluck('id'))->delete() : $rows->count();
                });
            }
            $files = 0;
            $failed = false;
            if ($this->option('orphan-reports')) {
                $disk = Storage::disk('local');
                foreach ($disk->allFiles('provider-reports') as $path) {
                    // Never follow arbitrary stored paths or delete other storage assets.
                    if (! preg_match('~^provider-reports/([1-9]\d*)/[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}\.pdf$~D', $path, $match)) continue;
                    if (in_array($match[1], $holds, true)) continue;
                    if ($disk->lastModified($path) >= now()->subDay()->timestamp) continue;
                    if (ProviderReport::where('file_path', $path)->exists()) continue;
                    if ($execute && ! $disk->delete($path)) { $failed = true; continue; }
                    $files++;
                }
            }
            $this->info(($execute ? 'Deleted' : 'Eligible (preview only)').": {$logs} routine audit entries; {$files} orphan report PDFs.");
            if ($failed) {
                $this->error('Some orphan PDFs could not be deleted; retry after resolving storage errors.');
                return self::FAILURE;
            }
            return self::SUCCESS;
        } finally {
            $lock->release();
        }
    }
}
