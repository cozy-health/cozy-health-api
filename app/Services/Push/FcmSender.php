<?php

namespace App\Services\Push;

use App\Models\AppNotification;
use App\Models\PushDevice;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class FcmSender implements PushSender
{
    private function accessToken(string $project): string
    {
        $path = config('push.credentials');
        $raw = config('push.credentials_json') ?: ($path && is_file($path) ? file_get_contents($path) : null);
        if (! $project || ! $raw) throw new \RuntimeException('Firebase credentials unavailable.');
        $credentials = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        if (isset($credentials['project_id']) && $credentials['project_id'] !== $project) {
            throw new \RuntimeException('Firebase project and credential do not match.');
        }
        return Cache::remember('push:oauth:'.hash('sha256', $project.$credentials['client_email']), 3000, function () use ($credentials) {
            $jwt = JWT::encode([
                'iss' => $credentials['client_email'], 'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => 'https://oauth2.googleapis.com/token', 'iat' => time(), 'exp' => time() + 3600,
            ], $credentials['private_key'], 'RS256');
            return Http::asForm()->timeout(15)->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $jwt,
            ])->throw()->json('access_token');
        });
    }

    /** Authorize and validate only: never delivers to a device or consumes an allowance. */
    public function verifyCredentials(): bool
    {
        try {
            $project = config('push.project_id');
            if (! $project) return false;
            $access = $this->accessToken($project);
            return Http::withToken($access)->timeout(15)->post('https://fcm.googleapis.com/v1/projects/'.rawurlencode($project).'/messages:send', [
                'validate_only' => true,
                'message' => ['topic' => 'cozy-configuration-verification', 'data' => ['test' => 'configuration-only']],
            ])->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    public function send(PushDevice $device, AppNotification $notification): string
    {
        if (config('age.enabled')) {
            $user = \App\Models\User::find($device->user_id);
            if (! $user || ! app(\App\Services\AgeEligibility::class)->allows($user)) return 'failed';
        }
        try {
            $project = config('push.project_id');
            if (! $project) return 'failed';
            $access = $this->accessToken($project);
        } catch (\Throwable) {
            // No message send has started; safely classify as configuration/auth failure.
            return 'failed';
        }
        try {
            $response = Http::withToken($access)->timeout(15)->post('https://fcm.googleapis.com/v1/projects/'.rawurlencode($project).'/messages:send', [
                'message' => [
                    'token' => $device->token,
                    'notification' => ['title' => $notification->title, 'body' => $notification->body],
                    'data' => ['notification_id' => $notification->id, 'user_id' => (string) $notification->user_id,
                        'type' => $notification->type, 'deep_link' => $notification->deep_link],
                    'android' => ['ttl' => '0s', 'notification' => ['tag' => $notification->id, 'channel_id' => 'gentle_reminders_v2', 'sound' => 'cozy_notification']],
                    'apns' => ['headers' => ['apns-expiration' => '0', 'apns-collapse-id' => $notification->id], 'payload' => ['aps' => ['sound' => 'cozy_notification.wav']]],
                ],
            ]);
            if ($response->successful()) return 'accepted';
            foreach ($response->json('error.details', []) as $detail) {
                if (($detail['errorCode'] ?? '') === 'UNREGISTERED') return 'invalid';
            }
            if (in_array($response->status(), [429, 500, 503])) return 'transient';
            return 'failed';
        } catch (\Throwable) {
            // Timeout/disconnection can happen after provider acceptance. Never replay blindly.
            return 'unknown';
        }
    }
}
