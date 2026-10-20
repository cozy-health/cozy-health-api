<?php
namespace App\Services;
use Illuminate\Support\Facades\DB;
class EmergencyEmailService
{
    // Call inside a transaction. Reservations count attempts, including failed mail.
    public function reserve(): void
    {
        abort_unless(config('launch.emergency_email.enabled') && config('launch.emergency_email.daily_limit') > 0, 503, 'Email is unavailable. Use the message or call options.');
        $day = now()->utc()->toDateString();
        DB::table('emergency_email_daily_quotas')->insertOrIgnore(['day' => $day, 'reserved' => 0]);
        $quota = DB::table('emergency_email_daily_quotas')->where('day', $day)->lockForUpdate()->first();
        abort_if($quota->reserved >= config('launch.emergency_email.daily_limit'), 429, 'Email sending limit reached. Use the message or call options.');
        DB::table('emergency_email_daily_quotas')->where('day', $day)->increment('reserved');
    }
}
