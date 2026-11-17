<?php
namespace App\Console\Commands;
use App\Models\StudentApplication;
use App\Services\StudentEvidence;
use Illuminate\Console\Command;
class PruneLaunchData extends Command
{
    protected $signature = 'launch:prune';
    protected $description = 'Delete expired private student evidence without removing review decisions';
    public function handle(): int
    {
        $failed = false;
        StudentApplication::whereNotNull('evidence_path')->where(function ($query) {
            $query->where('status', '!=', 'pending')->orWhereNull('evidence_delete_at')
                ->orWhere('evidence_delete_at', '<=', now())->orWhere('created_at', '<=', now()->subDays(14));
        })->eachById(function ($application) use (&$failed) {
            if (StudentEvidence::current($application)) return;
            if ($application->status === 'pending') StudentEvidence::expireForUser($application->user_id);
            if (!StudentEvidence::delete($application->fresh())) $failed = true;
        });
        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
