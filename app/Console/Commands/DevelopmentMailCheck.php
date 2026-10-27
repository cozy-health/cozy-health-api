<?php

namespace App\Console\Commands;

use App\Jobs\SendTestEmail;
use App\Services\DevelopmentMailSafety;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

class DevelopmentMailCheck extends Command
{
    protected $signature = 'mail:development-check {--smtp-check : Test SMTP connection/authentication without sending} {--send-test : Queue one generic email to the configured approved test inbox} {--delivery= : Read a delivery UUID without email contents}';
    protected $description = 'Inspect approved development email delivery without logging secrets or sending to arbitrary recipients';

    public function handle(DevelopmentMailSafety $safety): int
    {
        if (!$safety->development()) { $this->error('This command is restricted to the approved development environment.'); return self::FAILURE; }
        $this->line(json_encode(['mailer' => config('mail.default'), 'queue' => config('queue.default'),
            'smtp_host' => config('mail.mailers.smtp.host'), 'smtp_port' => config('mail.mailers.smtp.port'),
            'smtp_username_configured' => filled(config('mail.mailers.smtp.username')),
            'smtp_password_configured' => filled(config('mail.mailers.smtp.password')),
            'approved_test_recipient_configured' => filled(config('development_mail.test_recipient')),
            'webhook_configured' => filled(config('services.brevo.webhook_token'))], JSON_THROW_ON_ERROR));
        if ($id = $this->option('delivery')) {
            if (!Str::isUuid($id)) { $this->error('Use a delivery UUID.'); return self::FAILURE; }
            $row = DB::table('email_delivery_logs')->where('id', $id)->first(['id', 'event', 'status', 'created_at', 'updated_at']);
            $this->line(json_encode(['delivery' => $row], JSON_THROW_ON_ERROR));
        }
        if ($this->option('smtp-check')) {
            $transport = null;
            try {
                $transport = Mail::mailer('smtp')->getSymfonyTransport();
                if (!$transport instanceof EsmtpTransport) throw new \RuntimeException('SMTP transport is unavailable.');
                $transport->start();
                $this->info('SMTP connection and authentication accepted. No email was sent.');
            } catch (\Throwable) {
                $this->error('SMTP connection or authentication failed. Check development sender settings and credentials. No secret values logged.');
                return self::FAILURE;
            } finally { try { $transport?->stop(); } catch (\Throwable) {} }
        }
        if ($this->option('send-test')) {
            $recipient = config('development_mail.test_recipient');
            if (!is_string($recipient) || !$safety->recipientAllowed($recipient) || config('mail.default') !== 'smtp') {
                $this->error('Configure an approved development test inbox and SMTP transport first.'); return self::FAILURE;
            }
            $job = new SendTestEmail($recipient);
            dispatch($job);
            $this->line(json_encode(['delivery_id' => $job->deliveryId, 'status' => 'queued'], JSON_THROW_ON_ERROR));
            $this->info('Queued only. This does not confirm delivery or inbox arrival.');
        }
        return self::SUCCESS;
    }
}
