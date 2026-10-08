<?php
namespace App\Http\Controllers;
use App\Http\Requests\BrevoWebhookRequest;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class BrevoWebhookController extends Controller
{
    public function __invoke(BrevoWebhookRequest $request)
    {
        $data = $request->validated();
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
            $inserted = DB::table('bounces')->insertOrIgnore(['event_key' => $key, 'user_id' => $user?->id,
                'event' => $event, 'occurred_at' => $occurred, 'created_at' => now()]);
            if ($inserted && $user) {
                if ($event === 'hard_bounce') { $user->forceFill(['email_invalid' => true])->save(); }
                if (in_array($event, ['spam', 'unsubscribe'], true)) { $user->forceFill(['email_unsubscribed_at' => $occurred])->save(); }
            }
        });
        return response()->json(['message' => 'Event acknowledged.']);
    }
}
