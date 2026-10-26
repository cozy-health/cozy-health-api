<?php

namespace App\Services;

use App\Models\StudentApplication;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

final class StudentEvidence
{
    public static function current(StudentApplication $application): bool
    {
        return $application->status === 'pending' && $application->evidence_path
            && $application->evidence_delete_at?->isFuture()
            && $application->created_at->copy()->addDays(14)->isFuture();
    }

    /** A failed physical delete retains its encrypted path for launch:prune to retry. */
    public static function delete(StudentApplication $application): bool
    {
        if (!$application->evidence_path) return true;
        try {
            if (!Storage::disk('local')->delete($application->evidence_path)) throw new \RuntimeException('Evidence deletion failed');
            $application->update(['evidence_path' => null]);
            return true;
        } catch (\Throwable) {
            Log::warning('Private student evidence cleanup needs retry.');
            return false;
        }
    }

    public static function expireForUser(int $userId): void
    {
        $expired = DB::transaction(function () use ($userId) {
            $applications = StudentApplication::where('user_id', $userId)->where('status', 'pending')->lockForUpdate()->get();
            return $applications->filter(function ($application) {
                if (self::current($application)) return false;
                $application->update(['status' => 'expired', 'email_token_hash' => null, 'email_token_expires_at' => null, 'evidence_delete_at' => now()]);
                DB::table('student_review_events')->insert(['application_id' => $application->id, 'reviewer_id' => null, 'decision' => 'expired', 'note' => encrypt('Evidence expired after 14 days. Submit a new application.'), 'created_at' => now()]);
                return true;
            });
        });
        foreach ($expired as $application) self::delete($application);
    }
}
