<?php

namespace App\Services;

use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Event;
use Symfony\Component\Mime\Email;

final class DevelopmentMailSafety
{
    public function development(): bool
    {
        $url = parse_url((string) config('app.url'));
        return config('development_mail.project_id') === CommunityAvailability::DEVELOPMENT_PROJECT
            && config('development_mail.environment_id') === CommunityAvailability::DEVELOPMENT_ENVIRONMENT
            && is_array($url) && ($url['scheme'] ?? '') === 'https' && ($url['host'] ?? '') === 'api-dev.cozyhealth.org'
            && !isset($url['user']) && !isset($url['pass']) && !isset($url['query']) && !isset($url['fragment'])
            && (!isset($url['port']) || $url['port'] === 443);
    }

    public function recipientAllowed(string $email): bool
    {
        return in_array(strtolower(trim($email)), array_map(fn ($value) => strtolower(trim($value)), config('development_mail.allowed_recipients', [])), true);
    }

    public function assertRecipients(Email $message): void
    {
        if (config('development_mail.project_id') !== CommunityAvailability::DEVELOPMENT_PROJECT
            || config('development_mail.environment_id') !== CommunityAvailability::DEVELOPMENT_ENVIRONMENT) return;
        if (!$this->development()) throw new \RuntimeException('Development email environment identity is invalid.');
        $recipients = [...$message->getTo(), ...$message->getCc(), ...$message->getBcc()];
        if (!$recipients) throw new \RuntimeException('Development email has no approved recipient.');
        foreach ($recipients as $recipient) {
            if (!$this->recipientAllowed($recipient->getAddress())) throw new \RuntimeException('Development email recipient is not approved for testing.');
        }
    }

    public static function register(): void
    {
        Event::listen(MessageSending::class, function (MessageSending $event) {
            // Never render transactional codes or personal mail bodies into logs.
            if (config('mail.default') === 'log') {
                throw new \RuntimeException('Transactional email transport is unavailable. Log transport cannot deliver account emails.');
            }
            app(self::class)->assertRecipients($event->message);
        });
    }
}
