<?php
namespace App\Console\Commands;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
class PruneAnalytics extends Command
{
    protected $signature = 'analytics:prune {--older-than=14months}';
    protected $description = 'Remove anonymous analytics events older than the retention period';
    public function handle(): int
    {
        if (! preg_match('/^(\d+)months$/', $this->option('older-than'), $match) || (int) $match[1] < 1 || (int) $match[1] > 14) {
            $this->error('Choose 1months through 14months; retention cannot exceed fourteen months.'); return 1;
        }
        $deleted = DB::table('analytics_events')->where('created_at', '<', now()->subMonthsNoOverflow((int) $match[1]))->delete();
        $this->info("Pruned $deleted aggregate events."); return 0;
    }
}
