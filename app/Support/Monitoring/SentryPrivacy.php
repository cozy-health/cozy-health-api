<?php

namespace App\Support\Monitoring;

use Sentry\Event;
use Sentry\ExceptionDataBag;
use Sentry\Frame;
use Sentry\Stacktrace;

final class SentryPrivacy
{
    public static function beforeSend(Event $event): ?Event
    {
        $environment = config('app.env');
        $enabled = match ($environment) {
            'local', 'development' => config('monitoring.sentry_development_enabled'),
            'production' => config('monitoring.sentry_production_enabled'),
            default => false,
        };
        if (!$enabled) {
            return null;
        }

        // Rebuild from an allowlist. Never copy request, user, SQL, queue payload,
        // breadcrumbs, exception messages, local variables or source context.
        $safe = Event::createEvent($event->getId());
        $safe->setTimestamp($event->getTimestamp());
        $safe->setLevel($event->getLevel());
        $reportingEnvironment = $environment === 'production' ? 'production' : 'development';
        $safe->setEnvironment($reportingEnvironment);
        $release = config('sentry.release');
        if (is_string($release) && preg_match('/^cozy-health-api@[a-f0-9]{40}$/D', $release)) {
            $safe->setRelease($release);
        }
        $safe->setServerName('cozy-health-api-'.$reportingEnvironment);
        $safe->setTags(['component' => 'api']);
        $exceptions = [];
        foreach ($event->getExceptions() as $exception) {
            $clean = new ExceptionDataBag(new \RuntimeException('Error details withheld for privacy.'));
            $type = $exception->getType();
            $clean->setType(in_array($type, ['Exception', 'RuntimeException', 'LogicException', 'Error', 'TypeError', 'ValueError', 'ErrorException', 'InvalidArgumentException'], true) ? $type : 'ApplicationError');
            $frames = [];
            foreach ($exception->getStacktrace()?->getFrames() ?? [] as $frame) {
                $file = str_replace('\\', '/', $frame->getFile());
                $root = str_replace('\\', '/', base_path()).'/';
                if (str_starts_with($file, $root)) {
                    $file = substr($file, strlen($root));
                }
                // The SDK removes the project prefix but can retain its leading
                // separator (for example \app\Console\... on Windows).
                $file = ltrim($file, '/');
                if (!preg_match('~^(app|routes|bootstrap)/[A-Za-z0-9_./-]+\.php$~D', $file) || str_contains($file, '..')) {
                    continue;
                }
                $frames[] = new Frame(null, $file, $frame->getLine(), null, null, [], true);
            }
            if ($frames !== []) {
                $clean->setStacktrace(new Stacktrace($frames));
            }
            $exceptions[] = $clean;
        }
        if ($exceptions === []) {
            return null; // Arbitrary captureMessage/log content has no safe schema.
        }
        $safe->setExceptions($exceptions);
        return $safe;
    }
}
