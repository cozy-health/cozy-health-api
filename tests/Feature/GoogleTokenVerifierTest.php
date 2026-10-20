<?php

use App\Services\Google\GoogleTokenVerifier;
use App\Services\Google\InvalidGoogleTokenException;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;

function googleVerifierTestKey(string $kid = 'test-key'): array
{
    static $material;
    if ($material === null) {
        $options = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'config' => __DIR__.'/../Fixtures/openssl.cnf'];
        $key = openssl_pkey_new($options);
        if ($key === false) {
            throw new RuntimeException('Unable to create test RSA key: '.openssl_error_string());
        }
        if (! openssl_pkey_export($key, $privateKey, null, $options)) {
            throw new RuntimeException('Unable to export test RSA key: '.openssl_error_string());
        }
        $public = openssl_pkey_get_details($key);
        $material = [$privateKey, $public['rsa']];
    }

    return [
        'private' => $material[0],
        'jwk' => [
            'kid' => $kid, 'kty' => 'RSA', 'alg' => 'RS256', 'use' => 'sig',
            'n' => JWT::urlsafeB64Encode($material[1]['n']),
            'e' => JWT::urlsafeB64Encode($material[1]['e']),
        ],
    ];
}

function googleVerifierTestToken(array $overrides = [], string $kid = 'test-key'): string
{
    return JWT::encode(array_replace([
        'iss' => 'https://accounts.google.com', 'aud' => 'web-client',
        'exp' => time() + 3600, 'iat' => time() - 10,
        'sub' => 'google-123', 'email' => 'test@gmail.com', 'email_verified' => true,
        'name' => 'Test User', 'picture' => 'https://example.com/avatar.png',
    ], $overrides), googleVerifierTestKey($kid)['private'], 'RS256', $kid);
}

beforeEach(function () {
    Cache::flush();
    Http::preventStrayRequests();
    config(['services.google.client_id' => 'web-client', 'services.google.ios_client_id' => 'ios-client',
        'services.google.android_client_id' => 'android-client']);
});

it('verifies signed google tokens for each configured audience and caches keys for one hour', function ($audience) {
    Http::fake(['www.googleapis.com/oauth2/v3/certs' => Http::response(['keys' => [googleVerifierTestKey()['jwk']]])]);
    $verifier = new GoogleTokenVerifier;
    expect($verifier->verify(googleVerifierTestToken(['aud' => $audience])))
        ->toMatchArray(['sub' => 'google-123', 'email_verified' => true, 'name' => 'Test User']);
    $verifier->verify(googleVerifierTestToken());
    Http::assertSentCount(1);
    $this->travel(3601)->seconds();
    $verifier->verify(googleVerifierTestToken());
    Http::assertSentCount(2);
})->with(['web-client', 'ios-client', 'android-client']);

it('accepts the alternative valid google issuer', function () {
    Http::fake(['www.googleapis.com/oauth2/v3/certs' => Http::response(['keys' => [googleVerifierTestKey()['jwk']]])]);
    expect((new GoogleTokenVerifier)->verify(googleVerifierTestToken(['iss' => 'accounts.google.com']))['sub'])
        ->toBe('google-123');
});

it('rejects invalid google claims in genuinely signed tokens', function ($overrides) {
    Http::fake(['www.googleapis.com/oauth2/v3/certs' => Http::response(['keys' => [googleVerifierTestKey()['jwk']]])]);
    expect(fn () => (new GoogleTokenVerifier)->verify(googleVerifierTestToken($overrides)))
        ->toThrow(InvalidGoogleTokenException::class);
})->with([
    'wrong audience' => [['aud' => 'attacker-client']],
    'array audience' => [['aud' => ['web-client', 'attacker-client']]],
    'wrong issuer' => [['iss' => 'https://attacker.example']],
    'expired' => [['exp' => time() - 60]],
    'missing expiry' => [['exp' => null]],
    'missing subject' => [['sub' => null]],
    'oversized subject' => [['sub' => str_repeat('a', 192)]],
    'invalid email' => [['email' => 'invalid']],
    'missing email verified' => [['email_verified' => null]],
    'string email verified false' => [['email_verified' => 'false']],
    'not yet valid' => [['nbf' => time() + 3600]],
]);

it('rejects tampered signatures', function () {
    Http::fake(['www.googleapis.com/oauth2/v3/certs' => Http::response(['keys' => [googleVerifierTestKey()['jwk']]])]);
    $segments = explode('.', googleVerifierTestToken());
    $segments[2] = JWT::urlsafeB64Encode(str_repeat('x', 256));
    expect(fn () => (new GoogleTokenVerifier)->verify(implode('.', $segments)))
        ->toThrow(InvalidGoogleTokenException::class);
});

it('rejects malformed tokens and symmetric algorithms without fetching keys', function ($token) {
    expect(fn () => (new GoogleTokenVerifier)->verify($token))->toThrow(InvalidGoogleTokenException::class);
    Http::assertNothingSent();
})->with([
    'malformed' => 'invalid',
    'wrong algorithm' => fn () => JWT::encode(['sub' => '123'], str_repeat('s', 64), 'HS256', 'test-key'),
]);

it('fails closed when all configured audiences are empty', function () {
    config(['services.google.client_id' => null, 'services.google.ios_client_id' => '', 'services.google.android_client_id' => null]);
    try {
        (new GoogleTokenVerifier)->verify(googleVerifierTestToken());
        $this->fail('Unconfigured Google sign-in must fail.');
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(503);
    }
    Http::assertNothingSent();
});

it('refreshes rotated keys and limits unknown key refreshes', function () {
    Cache::put('auth.google.jwks', ['keys' => [googleVerifierTestKey('old-key')['jwk']]], 3600);
    Http::fake(['www.googleapis.com/oauth2/v3/certs' => Http::response(['keys' => [googleVerifierTestKey('new-key')['jwk']]])]);
    $verifier = new GoogleTokenVerifier;
    expect($verifier->verify(googleVerifierTestToken([], 'new-key'))['sub'])->toBe('google-123');
    expect(fn () => $verifier->verify(googleVerifierTestToken([], 'unknown-key')))->toThrow(InvalidGoogleTokenException::class);
    Http::assertSentCount(1);
});

it('reports key retrieval failures as service unavailable', function ($response) {
    Http::fake(['www.googleapis.com/oauth2/v3/certs' => $response]);
    try {
        (new GoogleTokenVerifier)->verify(googleVerifierTestToken());
        $this->fail('Invalid provider keys must fail.');
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(503);
    }
    expect(Cache::has('auth.google.jwks'))->toBeFalse();
})->with([
    'http failure' => fn () => Http::response([], 500),
    'malformed keys' => fn () => Http::response(['keys' => []]),
    'missing keys' => fn () => Http::response(['error' => 'invalid']),
    'connection failure' => fn () => Http::failedConnection(),
]);
