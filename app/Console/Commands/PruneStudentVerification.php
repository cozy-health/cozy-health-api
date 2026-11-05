<?php

namespace App\Console\Commands;

use App\Models\StudentApplication;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneStudentVerification extends Command
{
    protected $signature = 'students:prune-verification';
    protected $description = 'Expire Student eligibility and enforce verification data retention';
    public function handle(): int
    {
        DB::table('student_email_challenges')->where('expires_at', '<=', now())->orWhere('attempts', '>=', 3)->delete();
        // Eligibility expiry does not alter store entitlements or purchase history.
        StudentApplication::where('status', 'approved')->where('eligible_until', '<=', now())->update(['status' => 'expired']);
        StudentApplication::where('eligible_until', '<=', now()->subDays(90))->whereNotNull('institution_email')->update(['institution_email' => null]);
        DB::table('student_school_requests')->where('status', '!=', 'pending')->where('reviewed_at', '<=', now()->subDays(90))->delete();
        $this->info('Student verification retention processed; paid entitlements preserved.');
        return self::SUCCESS;
    }
}
