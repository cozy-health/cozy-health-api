<?php
namespace App\Console\Commands;
use App\Models\StudentApplication;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
class PruneLaunchData extends Command
{
    protected $signature = 'launch:prune';
    protected $description = 'Delete expired private student evidence without removing review decisions';
    public function handle(): int
    {
        StudentApplication::whereNotNull('evidence_path')->where('evidence_delete_at', '<=', now())->eachById(function ($application) {
            if (Storage::disk('local')->delete($application->evidence_path)) $application->update(['evidence_path' => null]);
        });
        return self::SUCCESS;
    }
}
