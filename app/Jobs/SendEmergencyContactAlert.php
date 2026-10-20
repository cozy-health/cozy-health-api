<?php
namespace App\Jobs;
use App\Models\EmergencyEmailContact;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
class SendEmergencyContactAlert implements ShouldQueue, ShouldBeEncrypted
{
    use Queueable;
    // A mail-provider timeout may occur after acceptance. Do not duplicate an urgent alert.
    public int $tries = 1;
    public int $timeout = 30;
    public function __construct(public string $alertId) {}
    public function handle(): void
    {
        $alert = DB::table('emergency_email_alerts')->where('id', $this->alertId)->first();
        if (!$alert || $alert->status !== 'queued') return;
        $contact = EmergencyEmailContact::find($alert->contact_id);
        if (!$contact?->confirmed_at || !config('launch.emergency_email.enabled') || \Illuminate\Support\Carbon::parse($alert->created_at)->lt(now()->subMinutes(10))) {
            DB::table('emergency_email_alerts')->where('id', $this->alertId)->update(['status' => 'failed', 'updated_at' => now()]); return;
        }
        $claimed = DB::table('emergency_email_alerts')->where('id', $this->alertId)->where('status', 'queued')->update(['status' => 'sending', 'updated_at' => now()]);
        if (!$claimed) return;
        $unsubscribe = rtrim(config('app.url'), '/').'/api/v1/emergency-email/unsubscribe?id='.$contact->id.'&token='.urlencode($contact->unsubscribe_token);
        try {
            $name = \App\Models\User::find($alert->user_id)?->name ?? 'Your contact';
            Mail::raw($name." has requested support through Cozy Health. Please contact them using the details you already know.\n\nThis is an automated email, not an emergency-service dispatch. Email delivery and response are not guaranteed. If there is immediate danger, contact local emergency services.\n\nStop these alerts: ".$unsubscribe, fn ($mail) => $mail->to($contact->email)->subject('Cozy Health: a contact has requested support'));
            DB::table('emergency_email_alerts')->where('id', $this->alertId)->update(['status' => 'mail_service_accepted', 'updated_at' => now()]);
        } catch (\Throwable) { $this->failed(); }
    }
    public function failed(?\Throwable $exception = null): void
    {
        DB::table('emergency_email_alerts')->where('id', $this->alertId)->whereIn('status', ['queued', 'sending'])->update(['status' => 'failed', 'updated_at' => now()]);
    }
}
