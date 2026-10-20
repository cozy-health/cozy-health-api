<?php

namespace App\Services\Google;

use DomainException;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use UnexpectedValueException;

class GoogleTokenVerifier
{
    private const KEYS_URL = 'https://www.googleapis.com/oauth2/v3/certs';

    private const CACHE_KEY = 'auth.google.jwks';

    public function verify(string $idToken): array
    {
        $audiences = array_values(array_filter([
            config('services.google.client_id'),
            config('services.google.ios_client_id'),
            config('services.google.android_client_id'),
        ], fn ($value) => is_string($value) && trim($value) !== ''));

        abort_if($audiences === [], 503, 'Google sign-in is not configured.');

        try {
            $segments = explode('.', $idToken);
            if (count($segments) !== 3) {
                throw new UnexpectedValueException('Invalid JWT segments.');
            }

            // The untrusted header selects a key only; JWT::decode verifies the signature.
            $header = JWT::jsonDecode(JWT::urlsafeB64Decode($segments[0]));
            if (! is_object($header) || ($header->alg ?? null) !== 'RS256'
                || ! is_string($header->kid ?? null) || $header->kid === '') {
                throw new UnexpectedValueException('Invalid JWT header.');
            }

            $jwks = Cache::remember(self::CACHE_KEY, 3600, fn () => $this->fetchKeys());
            $keys = JWK::parseKeySet($jwks, 'RS256');

            // Refresh on rotation, bounded to one refresh per minute for unknown key IDs.
            if (! isset($keys[$header->kid]) && Cache::add(self::CACHE_KEY.'.refresh', true, 60)) {
                $jwks = $this->fetchKeys();
                Cache::put(self::CACHE_KEY, $jwks, 3600);
                $keys = JWK::parseKeySet($jwks, 'RS256');
            }

            $claims = (array) JWT::decode($idToken, $keys);
            if (! is_string($claims['aud'] ?? null) || ! in_array($claims['aud'], $audiences, true)
                || ! in_array($claims['iss'] ?? null, ['accounts.google.com', 'https://accounts.google.com'], true)
                || ! is_int($claims['exp'] ?? null) || $claims['exp'] <= time()
                || ! is_string($claims['sub'] ?? null) || trim($claims['sub']) === '' || strlen($claims['sub']) > 191
                || ! is_string($claims['email'] ?? null) || strlen($claims['email']) > 190
                || ! filter_var($claims['email'], FILTER_VALIDATE_EMAIL)
                || ! is_bool($claims['email_verified'] ?? null)) {
                throw new UnexpectedValueException('Invalid JWT claims.');
            }

            return [
                'sub' => $claims['sub'],
                'email' => $claims['email'],
                'email_verified' => $claims['email_verified'],
                'name' => is_string($claims['name'] ?? null) && trim($claims['name']) !== ''
                    ? mb_substr(trim($claims['name']), 0, 120) : null,
                'picture' => is_string($claims['picture'] ?? null) ? $claims['picture'] : null,
            ];
        } catch (DomainException|InvalidArgumentException|UnexpectedValueException $e) {
            throw new InvalidGoogleTokenException('Invalid Google ID token.', 0, $e);
        }
    }

    private function fetchKeys(): array
    {
        try {
            $response = Http::acceptJson()->connectTimeout(3)->timeout(10)->get(self::KEYS_URL)->throw();
        } catch (ConnectionException|RequestException $e) {
            abort(503, 'Google sign-in is temporarily unavailable.');
        }

        $jwks = $response->json();
        abort_unless(is_array($jwks) && is_array($jwks['keys'] ?? null), 503, 'Google sign-in is temporarily unavailable.');
        $jwks['keys'] = array_values(array_filter($jwks['keys'], fn ($key) => is_array($key)
            && ($key['kty'] ?? null) === 'RSA'
            && ($key['use'] ?? 'sig') === 'sig'
            && ($key['alg'] ?? 'RS256') === 'RS256'));
        abort_if($jwks['keys'] === [], 503, 'Google sign-in is temporarily unavailable.');

        try {
            JWK::parseKeySet($jwks, 'RS256');
        } catch (DomainException|InvalidArgumentException|UnexpectedValueException $e) {
            abort(503, 'Google sign-in is temporarily unavailable.');
        }

        return $jwks;
    }
}
