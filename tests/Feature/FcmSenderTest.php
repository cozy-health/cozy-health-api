<?php

namespace Tests\Feature;

use App\Models\{AppNotification, PushDevice};
use App\Services\Push\FcmSender;
use Illuminate\Support\Facades\{Cache, Http};
use Tests\TestCase;

class FcmSenderTest extends TestCase
{
    public function test_credential_check_always_uses_validate_only_and_no_account_data(): void
    {
        config(['push.project_id' => 'fixture-project', 'push.credentials_json' => json_encode(['client_email' => 'fixture@example.test'])]);
        Cache::put('push:oauth:'.hash('sha256', 'fixture-projectfixture@example.test'), 'fixture-access', 3000);
        Http::fake(['fcm.googleapis.com/*' => Http::response(['name' => 'validated'], 200)]);
        $this->artisan('push:credentials-check')->assertSuccessful();
        Http::assertSent(fn ($request) => $request['validate_only'] === true
            && $request['message']['topic'] === 'cozy-configuration-verification'
            && ! isset($request['message']['token'])
            && ! isset($request['message']['notification']));
    }

    public function test_mismatched_project_fails_without_making_a_request(): void
    {
        config(['push.project_id' => 'fixture-project', 'push.credentials_json' => json_encode(['project_id' => 'other-project', 'client_email' => 'fixture@example.test'])]);
        Http::fake();
        $this->assertFalse(app(FcmSender::class)->verifyCredentials());
        Http::assertNothingSent();
    }

    public function test_provider_results_are_classified_without_exposing_credentials(): void
    {
        config(['push.project_id' => 'fixture-project', 'push.credentials_json' => json_encode(['client_email' => 'fixture@example.test'])]);
        Cache::put('push:oauth:'.hash('sha256', 'fixture-projectfixture@example.test'), 'fixture-access', 3000);
        $device = new PushDevice(['token' => str_repeat('x', 64)]);
        $notification = new AppNotification(['id' => 'fixture-id', 'user_id' => 42, 'type' => 'mood',
            'title' => 'A moment', 'body' => 'Check in', 'deep_link' => '/mood-feeling']);
        $results = [
            [200, ['name' => 'projects/fixture/messages/one'], 'accepted'],
            [429, ['error' => ['status' => 'RESOURCE_EXHAUSTED']], 'transient'],
            [503, ['error' => ['status' => 'UNAVAILABLE']], 'transient'],
            [404, ['error' => ['details' => [['errorCode' => 'UNREGISTERED']]]], 'invalid'],
            [400, ['error' => ['status' => 'INVALID_ARGUMENT']], 'failed'],
        ];
        $sequence = Http::sequence();
        foreach ($results as [$code, $body]) $sequence->push($body, $code);
        Http::fake(['fcm.googleapis.com/*' => $sequence]);
        foreach ($results as [$code, $body, $expected]) {
            $this->assertSame($expected, app(FcmSender::class)->send($device, $notification));
        }
        Http::assertSent(fn ($request) => $request['message']['data']['user_id'] === '42'
            && $request['message']['android']['ttl'] === '0s'
            && $request['message']['android']['notification']['channel_id'] === 'gentle_reminders_v2'
            && $request['message']['apns']['payload']['aps']['sound'] === 'cozy_notification.wav'
            && $request['message']['notification']['body'] === 'Check in');
    }

    public function test_missing_credentials_fail_closed_without_an_external_request(): void
    {
        config(['push.project_id' => null, 'push.credentials_json' => null, 'push.credentials' => null]);
        Http::fake();
        $this->assertSame('failed', app(FcmSender::class)->send(new PushDevice(), new AppNotification()));
        Http::assertNothingSent();
    }
}
