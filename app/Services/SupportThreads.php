<?php

namespace App\Services;

use App\Mail\SupportReplyMail;
use App\Models\AppNotification;
use App\Models\SupportMessage;
use App\Models\SupportReport;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class SupportThreads
{
    public static function recordVersion(array $row): string
    {
        return hash('sha256', json_encode([$row['id'], $row['status'], $row['admin_notes'], $row['updated_at'], $row['resolved_at'], $row['support_revision'] ?? 0], JSON_THROW_ON_ERROR));
    }

    public function detail(SupportReport $report, bool $staff = false): array
    {
        $result = $report->only(['id', 'subject', 'message', 'status', 'created_at', 'updated_at', 'resolved_at']);
        $result['version'] = (int) $report->support_revision;
        $result['messages'] = SupportMessage::where('support_report_id', $report->id)->orderBy('sequence')->get()->map(function ($message) use ($staff) {
            $data = $message->only(['id', 'author', 'body', 'created_at']);
            if ($staff && $message->author === 'support') {
                $data['notification_status'] = $message->delivery_id
                    ? DB::table('email_delivery_logs')->where('id', $message->delivery_id)->value('status') ?? 'queued' : 'failed';
            }

            return $data;
        })->all();
        if ($staff) {
            $result['admin_notes'] = $report->admin_notes;
            $result['record_version'] = self::recordVersion($report->getAttributes());
        }

        return $result;
    }

    public function reply(int $id, array $data, string $author, User $actor): SupportReport
    {
        return DB::transaction(function () use ($id, $data, $author, $actor) {
            $report = SupportReport::whereKey($id)->when($author === 'customer', fn ($q) => $q->where('user_id', $actor->id))->lockForUpdate()->firstOrFail();
            $existing = SupportMessage::where('support_report_id', $id)->where('author', $author)->where('request_id', $data['request_id'])->first();
            if ($existing) {
                abort_unless($existing->body === $data['body'], 409, 'This request was already used for another message.');

                return $report;
            }
            abort_unless((int) $report->support_revision === $data['expected_version'], 409, 'This conversation changed. Refresh before replying; your draft is still available.');
            $message = SupportMessage::create(['id' => (string) Str::uuid(), 'support_report_id' => $id,
                'author' => $author, 'body' => $data['body'], 'request_id' => $data['request_id'], 'sequence' => $report->support_revision + 1]);
            $report->support_revision++;
            if ($author === 'customer') {
                $report->status = 'open';
                $report->resolved_at = null;
            }
            $report->save();
            if ($author === 'support') {
                AdminAudit::record($actor->id, 'reply', 'support-reports', (string) $id);
                AppNotification::withoutGlobalScopes()->create(['id' => (string) Str::uuid(), 'user_id' => $report->user_id,
                    'type' => 'support', 'title' => 'Support update', 'body' => 'Open Help & Support to read your update.',
                    'deep_link' => '/support-inbox', 'data' => [], 'is_read' => false, 'client_created_at' => now()]);
                $this->notify($message, $report);
            }

            return $report;
        }, 3);
    }

    public function notify(SupportMessage $message, SupportReport $report): void
    {
        $mail = new SupportReplyMail($message->id);
        $message->update(['delivery_id' => $mail->deliveryId]);
        DB::table('email_delivery_logs')->insert(['id' => $mail->deliveryId, 'event' => 'support-reply', 'status' => 'queued', 'created_at' => now(), 'updated_at' => now()]);
        try {
            $user = User::find($report->user_id);
            if (! $user || ! $user->is_active || ! $user->email_verified_at || $user->email_invalid) {
                DB::table('email_delivery_logs')->where('id', $mail->deliveryId)->update(['status' => 'suppressed', 'updated_at' => now()]);

                return;
            }
            // Same database transaction as the message/outbox: no dispatch gap.
            Mail::to($user->email)->queue($mail->onConnection('database')->beforeCommit());
        } catch (\Throwable) {
            DB::table('email_delivery_logs')->where('id', $mail->deliveryId)->update(['status' => 'failed', 'updated_at' => now()]);
        }
    }
}
