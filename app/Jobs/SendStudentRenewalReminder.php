<?php

namespace App\Jobs;

use App\Mail\BrandedNoticeMail;
use App\Models\StudentApplication;
use App\Models\User;
use App\Services\StudentEligibility;
use Illuminate\Contracts\Queue\{ShouldBeEncrypted, ShouldQueue};
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\{DB, Mail};

class SendStudentRenewalReminder implements ShouldQueue, ShouldBeEncrypted
{
    use Queueable;
    public int $tries = 3;
    public int $timeout = 30;
    public bool $failOnTimeout = true;
    public function __construct(public string $reminderId) { $this->onQueue('email'); }
    public function backoff(): array { return [30, 120, 300]; }

    public function handle(): void
    {
        // Hold the account and reminder locks through delivery to serialize renewal and duplicate jobs.
        // A transport failure rolls back the claim so the queue's bounded retry can deliver it.
        $snapshot = DB::table('student_renewal_reminders')->where('id', $this->reminderId)->first();
        if (!$snapshot) return;
        $application = StudentApplication::find($snapshot->application_id);
        if (!$application) return;
        DB::transaction(function () use ($application) {
            $user = User::whereKey($application->user_id)->lockForUpdate()->first();
            $row = DB::table('student_renewal_reminders')->where('id', $this->reminderId)->lockForUpdate()->first();
            if (!$row || !in_array($row->status, ['pending', 'failed'], true)) return;
            $current = app(StudentEligibility::class)->current($application->user_id);
            $expired = !$current && $application->eligible_until && $application->eligible_until->lte(now());
            $window = $expired ? 0 : ($current ? ($current->eligible_until->lte(now()->addDay()) ? 1 : ($current->eligible_until->lte(now()->addDays(7)) ? 7 : 30)) : null);
            if (!config('launch.student.enabled') || !$user || !$user->is_active || !$user->email_verified_at || $user->email_invalid
                || !hash_equals($row->email_key, StudentEligibility::emailKey($user->email)) || (!$expired && $current?->id !== $application->id) || $window !== (int) $row->days) {
                DB::table('student_renewal_reminders')->where('id', $this->reminderId)->update(['status' => 'obsolete', 'updated_at' => now()]);
                return;
            }
            $mail = new BrandedNoticeMail('Renew your Student eligibility', $expired ? 'Verify your school email again' : 'Your Student eligibility is expiring',
                ['Your Student eligibility expiry date is '.$application->eligible_until->toDateString().'.', 'Open Student verification in Cozy Health to verify your school email again.', 'Renewal opens 30 days before expiry. Your already-paid subscription period is preserved; renewing eligibility does not activate or charge for a subscription.']);
            $mail->deliveryId = $this->reminderId;
            Mail::to($user->email)->send($mail);
            DB::table('student_renewal_reminders')->where('id', $this->reminderId)->update(['status' => 'sent', 'updated_at' => now()]);
        });
    }

    public function failed(?\Throwable $error): void
    {
        DB::table('student_renewal_reminders')->where('id', $this->reminderId)->where('status', 'pending')->update(['status' => 'failed', 'updated_at' => now()]);
    }
}
