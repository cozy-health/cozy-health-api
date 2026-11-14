<?php

namespace Tests\Feature;

use App\Support\Monitoring\SentryPrivacy;
use Sentry\Event;
use Sentry\ExceptionDataBag;
use Sentry\Frame;
use Sentry\Stacktrace;
use Tests\TestCase;

final class SentryPrivacyTest extends TestCase
{
    public function test_production_reporting_requires_explicit_identity_and_destination(): void
    {
        $values = [
            'APP_ENV' => 'production', 'APP_URL' => 'https://api.cozyhealth.org',
            'RAILWAY_PROJECT_ID' => '33f9a938-49e3-42c5-83c7-633ee85760fa',
            'RAILWAY_ENVIRONMENT_ID' => '9ffd3fd1-ae52-4cc7-903e-35b529a151ae',
            'SENTRY_ENABLED' => 'true', 'SENTRY_ALLOWED_HOST' => 'production.ingest.de.sentry.io',
            'SENTRY_PROJECT_ID' => '123', 'SENTRY_LARAVEL_DSN' => 'https://public@production.ingest.de.sentry.io/123',
        ];
        $original = [];
        foreach ($values as $name => $value) {
            $original[$name] = [getenv($name), $_ENV[$name] ?? null, $_SERVER[$name] ?? null];
            putenv($name.'='.$value);
            $_ENV[$name] = $_SERVER[$name] = $value;
        }
        try {
            $monitoring = require base_path('config/monitoring.php');
            $this->assertTrue($monitoring['sentry_production_enabled']);
            $this->assertFalse($monitoring['sentry_development_enabled']);
            config(['app.env' => 'production', 'monitoring' => $monitoring]);
            $configured = require base_path('config/sentry.php');
            $this->assertSame($values['SENTRY_LARAVEL_DSN'], $configured['dsn']);
            $this->assertSame('production', $configured['environment']);
            $_ENV['SENTRY_ALLOWED_HOST'] = $_SERVER['SENTRY_ALLOWED_HOST'] = 'other.example';
            putenv('SENTRY_ALLOWED_HOST=other.example');
            $this->assertNull((require base_path('config/sentry.php'))['dsn']);
            $_ENV['RAILWAY_ENVIRONMENT_ID'] = $_SERVER['RAILWAY_ENVIRONMENT_ID'] = '56b85cc3-e80b-4cb9-a0d4-b47abe8586f6';
            putenv('RAILWAY_ENVIRONMENT_ID=56b85cc3-e80b-4cb9-a0d4-b47abe8586f6');
            $this->assertFalse((require base_path('config/monitoring.php'))['sentry_production_enabled']);
        } finally {
            foreach ($original as $name => [$value, $env, $server]) {
                $value === false ? putenv($name) : putenv($name.'='.$value);
                if ($env === null) { unset($_ENV[$name]); } else { $_ENV[$name] = $env; }
                if ($server === null) { unset($_SERVER[$name]); } else { $_SERVER[$name] = $server; }
            }
        }
    }

    public function test_production_diagnostics_use_the_same_privacy_filter(): void
    {
        config(['app.env' => 'production', 'monitoring.sentry_production_enabled' => true]);
        $event = Event::createEvent();
        $event->setMessage('private journal');
        $event->setRequest(['data' => 'private journal']);
        $event->setExceptions([new ExceptionDataBag(new \RuntimeException('private journal'))]);
        $safe = SentryPrivacy::beforeSend($event);
        $this->assertNotNull($safe);
        $this->assertSame('production', $safe->getEnvironment());
        $this->assertNull($safe->getMessage());
        $this->assertSame([], $safe->getRequest());
        $this->assertSame('Error details withheld for privacy.', $safe->getExceptions()[0]->getValue());
        config(['monitoring.sentry_production_enabled' => false]);
        $this->assertNull(SentryPrivacy::beforeSend($event));
    }

    public function test_sdk_prefix_relative_source_paths_retain_safe_file_and_line(): void
    {
        config(['app.env' => 'development', 'monitoring.sentry_development_enabled' => true]);
        $event = Event::createEvent();
        $event->setExceptions([new ExceptionDataBag(new \RuntimeException('private'), new Stacktrace([
            new Frame('private', '\\app\\Console\\Commands\\MonitoringFailure.php', 19, null, null, ['secret' => 'private']),
            new Frame(null, '/storage/private-patient.php', 1),
            new Frame(null, '/app/../storage/private-patient.php', 1),
        ]))]);
        $safe = SentryPrivacy::beforeSend($event);
        $this->assertNotNull($safe);
        $frames = $safe->getExceptions()[0]->getStacktrace()->getFrames();
        $this->assertCount(1, $frames);
        $this->assertSame('app/Console/Commands/MonitoringFailure.php', $frames[0]->getFile());
        $this->assertSame(19, $frames[0]->getLine());
        $this->assertSame([], $frames[0]->getVars());
        $this->assertNull($frames[0]->getFunctionName());
    }

    public function test_railway_activation_requires_exact_development_identity(): void
    {
        $names = ['APP_ENV', 'APP_URL', 'RAILWAY_PROJECT_ID', 'RAILWAY_ENVIRONMENT_ID', 'SENTRY_DEVELOPMENT_ENABLED'];
        $original = [];
        foreach ($names as $name) {
            $original[$name] = [getenv($name), $_ENV[$name] ?? null, $_SERVER[$name] ?? null];
        }
        $development = ['APP_ENV' => 'development', 'APP_URL' => 'https://api-dev.cozyhealth.org',
            'RAILWAY_PROJECT_ID' => '33f9a938-49e3-42c5-83c7-633ee85760fa',
            'RAILWAY_ENVIRONMENT_ID' => '56b85cc3-e80b-4cb9-a0d4-b47abe8586f6', 'SENTRY_DEVELOPMENT_ENABLED' => 'true'];
        try {
            foreach ([[$development, true], [array_replace($development, ['APP_ENV' => 'production']), false],
                [array_replace($development, ['APP_URL' => 'https://api.cozyhealth.org']), false],
                [array_replace($development, ['RAILWAY_ENVIRONMENT_ID' => 'other']), false],
                [array_replace($development, ['SENTRY_DEVELOPMENT_ENABLED' => 'false']), false]] as [$values, $expected]) {
                foreach ($values as $name => $value) {
                    putenv($name.'='.$value);
                    $_ENV[$name] = $_SERVER[$name] = $value;
                }
                $this->assertSame($expected, (require base_path('config/monitoring.php'))['sentry_development_enabled']);
            }
        } finally {
            foreach ($original as $name => [$value, $env, $server]) {
                $value === false ? putenv($name) : putenv($name.'='.$value);
                if ($env === null) { unset($_ENV[$name]); } else { $_ENV[$name] = $env; }
                if ($server === null) { unset($_SERVER[$name]); } else { $_SERVER[$name] = $server; }
            }
        }
    }

    public function test_only_approved_project_dsn_can_be_configured(): void
    {
        config(['monitoring.sentry_development_enabled' => true]);
        $original = getenv('SENTRY_LARAVEL_DSN');
        $originalEnv = $_ENV['SENTRY_LARAVEL_DSN'] ?? null;
        $originalServer = $_SERVER['SENTRY_LARAVEL_DSN'] ?? null;
        try {
            foreach (['https://key@attacker.example/4512362654990416',
                'https://key@o4512334056128512.ingest.de.sentry.io/production',
                'https://key:secret@o4512334056128512.ingest.de.sentry.io/4512362654990416',
                'https://key@o4512334056128512.ingest.de.sentry.io/4512362654990416?secret=value'] as $dsn) {
                putenv('SENTRY_LARAVEL_DSN='.$dsn);
                $_ENV['SENTRY_LARAVEL_DSN'] = $_SERVER['SENTRY_LARAVEL_DSN'] = $dsn;
                $settings = require base_path('config/sentry.php');
                $this->assertNull($settings['dsn']);
            }
            $dsn = 'https://key@o4512334056128512.ingest.de.sentry.io/4512362654990416';
            putenv('SENTRY_LARAVEL_DSN='.$dsn);
            $_ENV['SENTRY_LARAVEL_DSN'] = $_SERVER['SENTRY_LARAVEL_DSN'] = $dsn;
            $this->assertSame($dsn, (require base_path('config/sentry.php'))['dsn']);
            config(['monitoring.sentry_development_enabled' => false]);
            $this->assertNull((require base_path('config/sentry.php'))['dsn']);
        } finally {
            $original === false ? putenv('SENTRY_LARAVEL_DSN') : putenv('SENTRY_LARAVEL_DSN='.$original);
            if ($originalEnv === null) { unset($_ENV['SENTRY_LARAVEL_DSN']); } else { $_ENV['SENTRY_LARAVEL_DSN'] = $originalEnv; }
            if ($originalServer === null) { unset($_SERVER['SENTRY_LARAVEL_DSN']); } else { $_SERVER['SENTRY_LARAVEL_DSN'] = $originalServer; }
        }
    }

    public function test_laravel_exception_handler_delivers_only_filtered_event_to_transport(): void
    {
        config(['app.env' => 'development', 'monitoring.sentry_development_enabled' => true,
            'sentry.dsn' => 'https://example@example.com/1']);
        $transport = new class implements \Sentry\Transport\TransportInterface {
            public array $events = [];
            public function send(Event $event): \Sentry\Transport\Result
            {
                $this->events[] = $event;
                return new \Sentry\Transport\Result(\Sentry\Transport\ResultStatus::success(), $event);
            }
            public function close(?int $timeout = null): \Sentry\Transport\Result
            {
                return new \Sentry\Transport\Result(\Sentry\Transport\ResultStatus::success());
            }
        };
        $builder = app(\Sentry\ClientBuilder::class);
        $builder->setTransport($transport);
        $hub = app(\Sentry\State\HubInterface::class);
        $hub->bindClient($builder->getClient());
        report(new \RuntimeException('patient content and token must not leave'));
        $this->assertCount(1, $transport->events);
        $this->assertSame('Error details withheld for privacy.', $transport->events[0]->getExceptions()[0]->getValue());
        $this->assertSame([], $transport->events[0]->getRequest());
        $this->assertSame([], $transport->events[0]->getBreadcrumbs());
    }

    public function test_reconstructs_diagnostic_without_private_fields_or_stack_values(): void
    {
        config(['app.env' => 'development', 'monitoring.sentry_development_enabled' => true]);
        $event = Event::createEvent();
        $event->setMessage('private journal secret');
        $event->setRequest(['url' => 'https://api-dev.cozyhealth.org/private?token=secret', 'data' => 'health secret']);
        $event->setExtra(['queue_payload' => 'secret']);
        $event->setTags(['patient' => 'secret']);
        $event->setContext('private', ['content' => 'secret']);
        $frame = new Frame('secretFunction', base_path('app/Services/Example.php'), 17, null, null, ['password' => 'secret']);
        $frame->setContextLine('secret');
        $event->setExceptions([new ExceptionDataBag(new \RuntimeException('health secret'), new Stacktrace([
            $frame, new Frame('private', '/storage/patient-secret.php', 99),
        ]))]);
        $safe = SentryPrivacy::beforeSend($event);
        $this->assertNotNull($safe);
        $this->assertSame($event->getId(), $safe->getId());
        $this->assertSame('development', $safe->getEnvironment());
        $this->assertNull($safe->getMessage());
        $this->assertSame([], $safe->getRequest());
        $this->assertSame([], $safe->getExtra());
        $this->assertSame([], $safe->getContexts());
        $this->assertSame(['component' => 'api'], $safe->getTags());
        $exception = $safe->getExceptions()[0];
        $this->assertSame('Error details withheld for privacy.', $exception->getValue());
        $frames = $exception->getStacktrace()->getFrames();
        $this->assertCount(1, $frames);
        $this->assertSame('app/Services/Example.php', $frames[0]->getFile());
        $this->assertSame(17, $frames[0]->getLine());
        $this->assertSame([], $frames[0]->getVars());
        $this->assertNull($frames[0]->getContextLine());
        $this->assertNull($frames[0]->getFunctionName());
    }

    public function test_disabled_and_non_development_environments_drop_events_and_refuse_probe(): void
    {
        foreach (['production', 'staging', 'testing'] as $environment) {
            config(['app.env' => $environment, 'monitoring.sentry_development_enabled' => true, 'sentry.dsn' => 'https://example@example.com/1']);
            $this->assertNull(SentryPrivacy::beforeSend(Event::createEvent()));
            $this->artisan('monitoring:development-failure')->assertExitCode(1);
        }
        config(['app.env' => 'development', 'monitoring.sentry_development_enabled' => false]);
        $this->assertNull(SentryPrivacy::beforeSend(Event::createEvent()));
        $this->artisan('monitoring:development-failure')->assertExitCode(1);
    }

    public function test_unstructured_messages_are_dropped_and_sensitive_collection_is_disabled(): void
    {
        config(['app.env' => 'development', 'monitoring.sentry_development_enabled' => true]);
        $event = Event::createEvent();
        $event->setMessage('private content');
        $this->assertNull(SentryPrivacy::beforeSend($event));
        $this->assertFalse(config('sentry.send_default_pii'));
        $this->assertFalse(config('sentry.enable_logs'));
        $this->assertSame('never', config('sentry.max_request_body_size'));
        $this->assertSame(0.0, config('sentry.traces_sample_rate'));
        $this->assertSame(0.0, config('sentry.profiles_sample_rate'));
        $this->assertSame(0, config('sentry.max_breadcrumbs'));
    }
}
