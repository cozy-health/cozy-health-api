<?php
namespace App\Services;
use Illuminate\Support\Facades\{DB, Log, Schema};
use Illuminate\Support\Str;
class EmailDeliveryTracking
{
    public static function key(string $id): string { return hash_hmac('sha256', trim($id, '<>'), config('app.key')); }
    public static function safely(callable $callback): void
    {
        try { if (Schema::hasTable('email_delivery_logs')) $callback(); }
        catch (\Throwable) { Log::warning('Email delivery tracking unavailable.'); }
    }
    public static function register(): void
    {
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Queue\Events\JobQueued::class, function ($event) {
            $mailable = $event->job instanceof \Illuminate\Mail\SendQueuedMailable ? $event->job->mailable : null;
            $name = $mailable ? AdminEmailRegistry::event(get_class($mailable)) : ($event->job instanceof \App\Jobs\SendTestEmail ? 'queue-test' : null);
            if ($event->job instanceof \App\Jobs\SendStorePlanEmail) $name = 'plan-status';
            $id = $mailable?->deliveryId ?? ($event->job->deliveryId ?? null);
            if (!$name || !$id) return;
            self::safely(function () use ($event, $id, $name) {
                DB::table('email_delivery_logs')->insertOrIgnore(['id' => $id, 'event' => $name, 'status' => 'queued', 'created_at' => now(), 'updated_at' => now()]);
                if ($event->id !== null) DB::table('email_delivery_logs')->where('id', $id)->update(['queue_key' => self::key($event->connectionName.'|'.$event->queue.'|'.$event->id)]);
            });
        });
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Mail\Events\MessageSending::class, function ($event) {
            $name = AdminEmailRegistry::event($event->data['__laravel_mailable'] ?? '');
            if (!$name && $event->message->getSubject() !== 'Cozy Health queue check') return;
            if (app(DevelopmentMailSafety::class)->development()) {
                $headers = $event->message->getHeaders();
                $custom = $headers->get('X-Mailin-custom')?->getBodyAsString();
                $headers->remove('X-Mailin-custom');
                $headers->addTextHeader('X-Mailin-custom', ($custom ? $custom.'|' : '').DevelopmentMailSafety::BREVO_MARKER);
            }
            $blocked = false;
            foreach ([...$event->message->getTo(), ...$event->message->getCc(), ...$event->message->getBcc()] as $recipient) {
                $email = strtolower($recipient->getAddress());
                if (\App\Models\User::whereRaw('LOWER(email) = ?', [$email])->where('email_invalid', true)->exists()
                    || \App\Models\Provider::whereRaw('LOWER(email) = ?', [$email])->where('email_invalid', true)->exists()) {
                    $blocked = true;
                    break;
                }
            }
            self::safely(function () use ($event, $name, $blocked) {
                $id = $event->message->getHeaders()->get('X-Cozy-Delivery')?->getBodyAsString() ?? (string) Str::uuid();
                if (!$event->message->getHeaders()->has('X-Cozy-Delivery')) $event->message->getHeaders()->addTextHeader('X-Cozy-Delivery', $id);
                DB::table('email_delivery_logs')->insertOrIgnore(['id' => $id, 'event' => $name ?? 'queue-test', 'status' => $blocked ? 'suppressed' : 'sending', 'created_at' => now(), 'updated_at' => now()]);
                DB::table('email_delivery_logs')->where('id', $id)->update(['status' => $blocked ? 'suppressed' : 'sending', 'updated_at' => now()]);
            });
            if ($blocked) return false;
        });
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Mail\Events\MessageSent::class, function ($event) {
            $id = $event->message->getHeaders()->get('X-Cozy-Delivery')?->getBodyAsString();
            if (!$id) return;
            self::safely(function () use ($event, $id) {
                $key = self::key($event->sent->getMessageId());
                // A provider webhook can arrive before the transport returns its message ID.
                $statuses = DB::table('email_delivery_logs')->where('message_key', $key)->pluck('status')->all();
                $status = in_array('suppressed', $statuses, true) ? 'suppressed' : (in_array('bounced', $statuses, true) ? 'bounced' : (in_array('delivered', $statuses, true) ? 'delivered' : 'sent'));
                DB::table('email_delivery_logs')->where('id', $id)->update(['status' => $status, 'message_key' => $key, 'updated_at' => now()]);
            });
        });
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Queue\Events\JobFailed::class, function ($event) {
            if ($event->job->getQueue() !== 'email') return;
            self::safely(function () use ($event) {
                $key = self::key($event->connectionName.'|'.$event->job->getQueue().'|'.$event->job->getJobId());
                if (!DB::table('email_delivery_logs')->where('queue_key', $key)->update(['status' => 'failed', 'updated_at' => now()])) {
                    DB::table('email_delivery_logs')->insert(['id' => (string) Str::uuid(), 'event' => 'email-queue', 'status' => 'failed', 'created_at' => now(), 'updated_at' => now()]);
                }
            });
        });
    }
}
