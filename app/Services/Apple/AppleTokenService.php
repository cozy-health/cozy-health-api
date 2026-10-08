<?php

namespace App\Services\Apple;

use App\Models\User;
use Firebase\JWT\JWT;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class AppleTokenService
{
    public function __construct(private AppleTokenVerifier $verifier) {}

    public function exchangeAuthorizationCode(string $code, array $identity): ?array
    {
        $clientId = $identity['aud'] ?? '';
        if (! $this->configured($clientId)) {
            Log::warning('Apple authorization code exchange skipped: signing configuration is missing.');

            return null;
        }

        $body = [
            'client_id' => $clientId,
            'client_secret' => $this->clientSecret($clientId),
            'code' => $code,
            'grant_type' => 'authorization_code',
        ];
        $redirectUri = config('services.apple.redirect_uri');
        if (is_string($redirectUri) && $redirectUri !== '') {
            $body['redirect_uri'] = $redirectUri;
        }

        try {
            $response = Http::asForm()->acceptJson()->connectTimeout(3)->timeout(10)
                ->post('https://appleid.apple.com/auth/token', $body);
        } catch (ConnectionException $e) {
            abort(503, 'Apple authorization is temporarily unavailable.');
        }

        if ($response->status() === 400) {
            throw new InvalidAppleTokenException('Invalid Apple authorization code.');
        }
        abort_unless($response->successful(), 503, 'Apple authorization is temporarily unavailable.');
        $tokens = $response->json();
        abort_unless(is_array($tokens) && is_string($tokens['refresh_token'] ?? null)
            && $tokens['refresh_token'] !== '' && is_string($tokens['id_token'] ?? null),
            503, 'Apple authorization is temporarily unavailable.');

        // Bind the exchanged code to the identity and client that are signing in.
        $exchangedIdentity = $this->verifier->verify($tokens['id_token']);
        if ($exchangedIdentity['sub'] !== $identity['sub'] || $exchangedIdentity['aud'] !== $clientId) {
            throw new InvalidAppleTokenException('Apple authorization code does not match the identity.');
        }

        return ['refresh_token' => $tokens['refresh_token'], 'client_id' => $clientId];
    }

    public function revoke(User $user): void
    {
        if ($user->apple_sub === null) {
            return;
        }

        try {
            $clientId = $user->apple_token_client_id;
            if (! is_string($clientId) || ! $this->configured($clientId)) {
                Log::warning('Apple revocation skipped: signing configuration or stored client ID is missing.');

                return;
            }
            $refreshToken = $user->apple_refresh_token;
            if (! is_string($refreshToken) || $refreshToken === '') {
                Log::warning('Apple revocation skipped: no stored refresh token.');

                return;
            }

            $response = Http::asForm()->acceptJson()->connectTimeout(3)->timeout(10)
                ->post('https://appleid.apple.com/auth/revoke', [
                    'client_id' => $clientId,
                    'client_secret' => $this->clientSecret($clientId),
                    'token' => $refreshToken,
                    'token_type_hint' => 'refresh_token',
                ]);
            if (! $response->successful()) {
                Log::warning('Apple revocation failed; local account deletion will continue.', ['status' => $response->status()]);
            }
        } catch (Throwable $e) {
            // Never log token values, private keys, provider responses or exception messages.
            Log::warning('Apple revocation failed; local account deletion will continue.', ['exception' => get_class($e)]);
        }
    }

    private function configured(string $clientId): bool
    {
        if ($clientId === '' || ! in_array($clientId, [
            config('services.apple.service_id'), config('services.apple.bundle_id'),
        ], true)) {
            return false;
        }

        foreach (['team_id', 'key_id', 'private_key'] as $key) {
            $value = config('services.apple.'.$key);
            if (! is_string($value) || trim($value) === '') {
                return false;
            }
        }

        return true;
    }

    private function clientSecret(string $clientId): string
    {
        try {
            $key = openssl_pkey_get_private(str_replace('\\n', "\n", config('services.apple.private_key')));
            $details = $key === false ? false : openssl_pkey_get_details($key);
            if (! $details || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_EC
                || ($details['ec']['curve_name'] ?? null) !== 'prime256v1') {
                abort(503, 'Apple signing configuration is invalid.');
            }

            $now = time();

            return JWT::encode([
                'iss' => config('services.apple.team_id'),
                'iat' => $now,
                'exp' => $now + 180 * 24 * 60 * 60,
                'aud' => 'https://appleid.apple.com',
                'sub' => $clientId,
            ], $key, 'ES256', config('services.apple.key_id'));
        } catch (\DomainException|\InvalidArgumentException $e) {
            abort(503, 'Apple signing configuration is invalid.');
        }
    }
}
