<?php

namespace App\Services;

use App\Jobs\SendStorePlanEmail;
use App\Models\User;
use Illuminate\Support\Facades\{Bus, DB, Log};
use Illuminate\Support\Str;

final class StorePlanNotifications
{
    public static function enabled(): bool
    {
        return (bool) config('launch.billing_enabled') && (bool) config('store-plans.emails_enabled');
    }

    public static function fingerprint(array $details): string
    {
        return hash('sha256', json_encode($details, JSON_THROW_ON_ERROR));
    }

    /** Called while the purchase owner's row is locked. No client fields enter this snapshot. */
    public function record(User $user, string $purchaseId, ?object $previous, array $details): ?string
    {
        if (!self::enabled() || !$user->email_verified_at || $user->email_invalid || !$user->is_active) return null;
        if ($previous) {
            $before = $previous->plan_details ? json_decode($previous->plan_details, true, flags: JSON_THROW_ON_ERROR) : null;
            // Restore/recheck is not a new purchase. Legacy records establish a baseline silently.
            if (!$before || self::fingerprint($before) === self::fingerprint($details)) return null;
        }
        if (!in_array($details['state'], ['active', 'trial', 'canceled', 'grace_period', 'billing_retry', 'on_hold', 'expired', 'revoked', 'paused'], true)) return null;
        if (!$previous && !in_array($details['state'], ['active', 'trial'], true)) return null;
        $key = hash('sha256', $purchaseId.':'.self::fingerprint($details));
        DB::table('store_plan_notifications')->insertOrIgnore([
            'id' => (string) Str::uuid(), 'user_id' => $user->id, 'store_purchase_id' => $purchaseId,
            'dedupe_key' => $key, 'email_key' => hash_hmac('sha256', $user->email, config('app.key')),
            'details' => json_encode($details, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now(),
        ]);
        return DB::table('store_plan_notifications')->where('dedupe_key', $key)->value('id');
    }

    public function releaseAfterAcknowledgement(string $purchaseId, array $details): void
    {
        if (!self::enabled()) return;
        DB::table('store_plan_notifications')->where('store_purchase_id', $purchaseId)
            ->where('dedupe_key', hash('sha256', $purchaseId.':'.self::fingerprint($details)))
            ->whereNull('ready_at')->update(['ready_at' => now()]);
        $this->dispatchPending();
    }

    public function dispatchPending(): int
    {
        if (!self::enabled()) return 0;
        $count = 0;
        foreach (DB::table('store_plan_notifications')->where('status', 'pending')->whereNotNull('ready_at')->whereNull('queued_at')->limit(100)->pluck('id') as $id) {
            try {
                $count += DB::transaction(function () use ($id) {
                    $row = DB::table('store_plan_notifications')->where('id', $id)->lockForUpdate()->first();
                    if (!$row || $row->status !== 'pending' || !$row->ready_at || $row->queued_at) return 0;
                    // The database queue insertion and outbox marker commit together.
                    Bus::dispatch((new SendStorePlanEmail($id))->onConnection('database')->beforeCommit());
                    DB::table('store_plan_notifications')->where('id', $id)->update(['queued_at' => now(), 'updated_at' => now()]);
                    return 1;
                }, 3);
            } catch (\Throwable) { Log::warning('Store plan email dispatch needs retry.'); }
        }
        return $count;
    }
}
