<?php

namespace App\Jobs;

use App\Mail\StorePlanStatusMail;
use App\Models\User;
use App\Services\StorePlanNotifications;
use Illuminate\Contracts\Queue\{ShouldBeEncrypted, ShouldQueue};
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\{DB, Mail};

class SendStorePlanEmail implements ShouldQueue, ShouldBeEncrypted
{
    use Queueable;
    public int $tries = 3;
    public int $timeout = 30;
    public bool $failOnTimeout = true;
    public function backoff(): array { return [30, 120, 300]; }
    public function __construct(public string $deliveryId) { $this->onQueue('email'); }

    public function handle(): void
    {
        $claimed = DB::transaction(function () {
            $row = DB::table('store_plan_notifications')->where('id', $this->deliveryId)->lockForUpdate()->first();
            if (!$row || !in_array($row->status, ['pending', 'failed'], true) || !$row->ready_at) return null;
            $user = User::find($row->user_id);
            $purchase = DB::table('store_purchases')->where('id', $row->store_purchase_id)->first();
            $details = json_decode($row->details, true, flags: JSON_THROW_ON_ERROR);
            $current = $purchase?->plan_details ? json_decode($purchase->plan_details, true, flags: JSON_THROW_ON_ERROR) : null;
            if (!StorePlanNotifications::enabled() || !$user || !$user->is_active || !$user->email_verified_at || $user->email_invalid
                || !hash_equals($row->email_key, hash_hmac('sha256', $user->email, config('app.key')))
                || !$current || (int) $purchase->plan_version !== (int) $row->plan_version
                || StorePlanNotifications::fingerprint($current) !== StorePlanNotifications::fingerprint($details)) {
                $this->obsolete();
                return null;
            }
            if (in_array($details['state'], ['active', 'trial', 'canceled', 'grace_period'], true)
                && !\Illuminate\Support\Carbon::parse($details['expires_at'])->isFuture()) {
                $this->obsolete();
                return null;
            }
            DB::table('store_plan_notifications')->where('id', $row->id)->update(['status' => 'sending', 'attempts' => min(255, $row->attempts + 1), 'updated_at' => now()]);
            return ['email' => $user->email, 'details' => $details];
        }, 3);
        if (!$claimed) return;
        try {
            $mail = new StorePlanStatusMail($claimed['details']);
            $mail->deliveryId = $this->deliveryId;
            Mail::to($claimed['email'])->send($mail);
            DB::table('store_plan_notifications')->where('id', $this->deliveryId)->update(['status' => 'sent', 'updated_at' => now()]);
        } catch (\Throwable $error) {
            DB::table('store_plan_notifications')->where('id', $this->deliveryId)->update(['status' => 'pending', 'updated_at' => now()]);
            throw $error;
        }
    }
    public function failed(?\Throwable $error = null): void
    {
        DB::table('store_plan_notifications')->where('id', $this->deliveryId)->whereIn('status', ['pending', 'sending'])->update(['status' => 'failed', 'updated_at' => now()]);
    }
    private function obsolete(): void
    {
        DB::table('store_plan_notifications')->where('id', $this->deliveryId)->update(['status' => 'obsolete', 'updated_at' => now()]);
        \App\Services\EmailDeliveryTracking::safely(fn () => DB::table('email_delivery_logs')->where('id', $this->deliveryId)->update(['status' => 'suppressed', 'updated_at' => now()]));
    }
}
