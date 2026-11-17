<?php
namespace App\Http\Controllers;
use App\Http\Requests\BrevoWebhookRequest;
use App\Models\User;
use App\Models\Provider;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class BrevoWebhookController extends Controller
{
    public function __invoke(BrevoWebhookRequest $request)
    {
        $data = $request->validated();
        $delivery = match (strtolower($data['event'])) {
            'delivered' => 'delivered', 'hardbounce', 'hard_bounce', 'softbounce', 'soft_bounce' => 'bounced',
            'blocked', 'spam', 'complaint', 'unsubscribe', 'unsubscribed', 'invalid_email' => 'suppressed', default => null,
        };
        if ($delivery) \App\Services\EmailDeliveryTracking::safely(function () use ($data, $delivery) {
            $messageKey = \App\Services\EmailDeliveryTracking::key($data['message-id']);
            $key = \App\Services\EmailDeliveryTracking::key($data['message-id'].'|'.$data['event'].'|'.$data['date']);
            $sent = DB::table('email_delivery_logs')->where('message_key', $messageKey)->first();
            DB::table('email_delivery_logs')->insertOrIgnore(['id' => (string) \Illuminate\Support\Str::uuid(),
                'event' => $sent?->event ?? 'provider-event', 'status' => $delivery, 'message_key' => $messageKey, 'provider_event_key' => $key,
                'created_at' => \Carbon\Carbon::parse($data['date'])->utc(), 'updated_at' => now()]);
            $priority = ['queued' => 0, 'sending' => 0, 'sent' => 1, 'delivered' => 2, 'bounced' => 3, 'suppressed' => 4, 'failed' => 1];
            if ($sent && ($priority[$delivery] ?? 0) >= ($priority[$sent->status] ?? 0)) DB::table('email_delivery_logs')->where('id', $sent->id)->update(['status' => $delivery, 'updated_at' => now()]);
        });
        $event = match (strtolower($data['event'])) {
            'hardbounce', 'hard_bounce', 'invalid_email' => 'hard_bounce',
            'softbounce', 'soft_bounce' => 'soft_bounce',
            'spam', 'complaint' => 'spam',
            'unsubscribe', 'unsubscribed' => 'unsubscribe',
            'blocked' => 'blocked', default => null,
        };
        if ($event === null) { return response()->json(['message' => 'Event acknowledged.']); }
        $email = strtolower($data['email']);
        $occurred = Carbon::parse($data['date'])->utc();
        // Deduplicate without retaining addresses, message content or raw payloads.
        $key = hash_hmac('sha256', json_encode([$event, $email, $data['message-id'], $occurred->toIso8601String()]), config('app.key'));
        DB::transaction(function () use ($email, $event, $occurred, $key) {
            $user = User::whereRaw('LOWER(email) = ?', [$email])->lockForUpdate()->first();
            $provider = Provider::whereRaw('LOWER(email) = ?', [$email])->lockForUpdate()->first();
            // Preserve patient event keys; keep provider evidence independently owned
            // so deleting one role cannot remove or recreate the other role's event.
            if ($user || ! $provider) {
                $inserted = DB::table('bounces')->insertOrIgnore(['event_key' => $key, 'user_id' => $user?->id,
                    'event' => $event, 'occurred_at' => $occurred, 'created_at' => now()]);
                if ($inserted && $user) {
                    if ($event === 'hard_bounce') { $user->forceFill(['email_invalid' => true])->save(); }
                    if (in_array($event, ['spam', 'unsubscribe'], true)) { $user->forceFill(['email_unsubscribed_at' => $occurred])->save(); }
                }
            }
            if ($provider) {
                $providerKey = hash_hmac('sha256', 'provider|'.$key, config('app.key'));
                $inserted = DB::table('bounces')->insertOrIgnore(['event_key' => $providerKey, 'provider_id' => $provider->id,
                    'event' => $event, 'occurred_at' => $occurred, 'created_at' => now()]);
                if ($inserted) {
                    if ($event === 'hard_bounce') { $provider->forceFill(['email_invalid' => true])->save(); }
                    if (in_array($event, ['spam', 'unsubscribe'], true)) { $provider->forceFill(['email_unsubscribed_at' => $occurred])->save(); }
                }
            }
        });
        return response()->json(['message' => 'Event acknowledged.']);
    }
}
