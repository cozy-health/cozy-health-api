<?php

namespace App\Services;

use App\Jobs\SendStudentRenewalReminder;
use App\Models\StudentApplication;
use App\Models\User;
use Illuminate\Support\Facades\{Bus, DB, Log};
use Illuminate\Support\Str;

final class StudentRenewalReminders
{
    public function tick(): int
    {
        if (!config('launch.student.enabled')) return 0;
        DB::table('student_email_challenges')->where('expires_at', '<=', now())->delete();
        StudentApplication::where('status', 'approved')->where('eligible_until', '>', now())
            ->where('eligible_until', '<=', now()->addDays(30))->eachById(function ($application) {
                $current = app(StudentEligibility::class)->current($application->user_id);
                if ($current?->id !== $application->id) return;
                $user = User::find($application->user_id);
                if (!$user || !$user->is_active || !$user->email_verified_at || $user->email_invalid) return;
                $days = $application->eligible_until->lte(now()->addDay()) ? 1 : ($application->eligible_until->lte(now()->addDays(7)) ? 7 : 30);
                DB::table('student_renewal_reminders')->insertOrIgnore(['id' => (string) Str::uuid(), 'application_id' => $application->id,
                    'days' => $days, 'status' => 'pending', 'email_key' => StudentEligibility::emailKey($user->email), 'created_at' => now(), 'updated_at' => now()]);
            });
        $count = 0;
        foreach (DB::table('student_renewal_reminders')->where('status', 'pending')->whereNull('queued_at')->limit(100)->pluck('id') as $id) {
            try {
                $count += DB::transaction(function () use ($id) {
                    $row = DB::table('student_renewal_reminders')->where('id', $id)->lockForUpdate()->first();
                    if (!$row || $row->status !== 'pending' || $row->queued_at) return 0;
                    Bus::dispatch((new SendStudentRenewalReminder($id))->onConnection('database')->beforeCommit());
                    DB::table('student_renewal_reminders')->where('id', $id)->update(['queued_at' => now(), 'updated_at' => now()]);
                    return 1;
                }, 3);
            } catch (\Throwable) { Log::warning('Student renewal reminder dispatch needs retry.'); }
        }
        return $count;
    }
}
