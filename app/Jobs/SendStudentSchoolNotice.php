<?php

namespace App\Jobs;

use App\Mail\BrandedNoticeMail;
use Illuminate\Contracts\Queue\{ShouldQueue, ShouldBeEncrypted};
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class SendStudentSchoolNotice implements ShouldQueue, ShouldBeEncrypted
{
    use Queueable;
    public int $tries = 5;
    public int $timeout = 30;
    public bool $failOnTimeout = true;
    public function __construct(public string $requestId, public string $recipient, public bool $decision = false) { $this->onConnection('database'); $this->onQueue('email'); }
    public function backoff(): array { return [60, 300, 900, 3600]; }
    public function handle(): void
    {
        DB::transaction(function () {
            $row = DB::table('student_school_requests')->where('id', $this->requestId)->lockForUpdate()->first();
            if (!$row) return;
            $key = $this->decision ? 'decision' : hash('sha256', $this->recipient);
            $sent = json_decode($row->notices_sent ?? '[]', true, flags: JSON_THROW_ON_ERROR);
            if (in_array($key, $sent, true)) return;
            $recipient = $this->decision ? DB::table('users')->where('id', $row->user_id)->value('email') : $this->recipient;
            if (!$recipient) return;
            $title = $this->decision ? 'School request '.$row->status : 'New school domain request';
            $mail = new BrandedNoticeMail($title, $title,
                [$row->institution, 'Email domain: '.$row->domain,
                    $this->decision ? 'Open Student verification in Cozy. Approval of a school still requires you to verify your own mailbox.' : 'Review the request in the development admin dashboard. Receiving this notice does not grant dashboard access.'], $this->decision ? [] : [['label' => 'Review school requests', 'url' => 'https://dashboard-dev.cozyhealth.org/admin/manage/student-applications']]);
            $mail->deliveryId = hash('sha256', $this->requestId.':'.$key);
            Mail::to($recipient)->send($mail);
            DB::table('student_school_requests')->where('id', $row->id)->update(['notices_sent' => json_encode([...$sent, $key], JSON_THROW_ON_ERROR)]);
        });
    }
}
