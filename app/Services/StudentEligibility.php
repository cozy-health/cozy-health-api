<?php

namespace App\Services;

use App\Models\StudentApplication;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class StudentEligibility
{
    public function current(int $userId): ?StudentApplication
    {
        return StudentApplication::where('user_id', $userId)->where('status', 'approved')
            ->where('eligible_until', '>', now())->orderByDesc('eligible_until')->first();
    }

    public function status(int $userId): array
    {
        $current = $this->current($userId);
        $pending = StudentApplication::where('user_id', $userId)->where('status', 'pending')->exists();
        return ['active' => $current !== null, 'verification_method' => $current?->verification_method,
            'expires_at' => $current?->eligible_until?->toIso8601String(),
            'renewal_available' => !$pending && (!$current || $current->eligible_until->lte(now()->addDays(30))),
            'renewal_reminder' => $current && $current->eligible_until->lte(now()->addDays(30))];
    }

    /** Call inside a transaction; serialize document and email approvals on the account. */
    public function assertCanApply(int $userId): void
    {
        User::whereKey($userId)->lockForUpdate()->firstOrFail();
        abort_if(StudentApplication::where('user_id', $userId)->where('status', 'pending')->exists(), 409, 'An application is already awaiting review.');
        $current = $this->current($userId);
        abort_if($current && $current->eligible_until->gt(now()->addDays(30)), 409, 'Renewal opens 30 days before your Student eligibility expires.');
    }

    public function approve(StudentApplication $application, ?int $reviewerId, string $note): void
    {
        $current = $this->current($application->user_id);
        $until = ($current?->eligible_until?->copy() ?? now())->addMonthsNoOverflow(12);
        $application->update(['status' => 'approved', 'reviewer_id' => $reviewerId, 'review_note' => $note,
            'reviewed_at' => now(), 'eligible_until' => $until, 'evidence_delete_at' => now(),
            'email_token_hash' => null, 'email_token_expires_at' => null]);
        DB::table('student_email_challenges')->where('user_id', $application->user_id)->delete();
        DB::table('student_renewal_reminders')->whereIn('application_id', StudentApplication::where('user_id', $application->user_id)->select('id'))
            ->whereIn('status', ['pending', 'failed'])->update(['status' => 'obsolete', 'updated_at' => now()]);
        DB::table('student_review_events')->insert(['application_id' => $application->id, 'reviewer_id' => $reviewerId,
            'decision' => 'approved', 'note' => encrypt($note), 'created_at' => now()]);
    }

    public static function emailKey(string $email): string
    {
        return hash_hmac('sha256', strtolower(trim($email)), config('app.key'));
    }
}
