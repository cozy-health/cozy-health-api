<?php

use App\Services\Apple\AppleTokenVerifier;
use App\Services\Apple\InvalidAppleTokenException;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;

function appleVerifierTestKey(string $kid = 'test-key'): array
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

function appleVerifierTestToken(array $overrides = [], string $kid = 'test-key'): string
{
    return JWT::encode(array_replace([
        'iss' => 'https://appleid.apple.com', 'aud' => 'service-id',
        'exp' => time() + 3600, 'iat' => time() - 10,
        'sub' => 'apple-123', 'email' => 'test@gmail.com', 'email_verified' => true,
        'is_private_email' => 'false',
    ], $overrides), appleVerifierTestKey($kid)['private'], 'RS256', $kid);
}

beforeEach(function () {
    Cache::flush();
    Http::preventStrayRequests();
    config(['services.apple.service_id' => 'service-id', 'services.apple.bundle_id' => 'bundle-id']);
});

it('verifies signed apple tokens for each configured audience and caches keys for one hour', function ($audience) {
    Http::fake(['appleid.apple.com/auth/keys' => Http::response(['keys' => [appleVerifierTestKey()['jwk']]])]);
    $verifier = new AppleTokenVerifier;
    expect($verifier->verify(appleVerifierTestToken(['aud' => $audience])))
        ->toMatchArray(['sub' => 'apple-123', 'email_verified' => true, 'is_private_email' => false]);
    $verifier->verify(appleVerifierTestToken());
    Http::assertSentCount(1);
    $this->travel(3601)->seconds();
    $verifier->verify(appleVerifierTestToken());
    Http::assertSentCount(2);
})->with(['service-id', 'bundle-id']);

it('rejects an alternative apple issuer', function () {
    Http::fake(['appleid.apple.com/auth/keys' => Http::response(['keys' => [appleVerifierTestKey()['jwk']]])]);
    expect(fn () => (new AppleTokenVerifier)->verify(appleVerifierTestToken(['iss' => 'accounts.apple.com'])))
        ->toThrow(InvalidAppleTokenException::class);
});

it('rejects invalid apple claims in genuinely signed tokens', function ($overrides) {
    Http::fake(['appleid.apple.com/auth/keys' => Http::response(['keys' => [appleVerifierTestKey()['jwk']]])]);
    expect(fn () => (new AppleTokenVerifier)->verify(appleVerifierTestToken($overrides)))
        ->toThrow(InvalidAppleTokenException::class);
})->with([
    'wrong audience' => [['aud' => 'attacker-client']],
    'array audience' => [['aud' => ['service-id', 'attacker-client']]],
    'wrong issuer' => [['iss' => 'https://attacker.example']],
    'expired' => [['exp' => time() - 60]],
    'missing expiry' => [['exp' => null]],
    'missing subject' => [['sub' => null]],
    'oversized subject' => [['sub' => str_repeat('a', 192)]],
    'invalid email' => [['email' => 'invalid']],
    'missing email verified' => [['email_verified' => null]],
    'numeric email verified' => [['email_verified' => 1]],
    'invalid private email' => [['is_private_email' => 'yes']],
    'not yet valid' => [['nbf' => time() + 3600]],
]);

it('rejects tampered signatures', function () {
    Http::fake(['appleid.apple.com/auth/keys' => Http::response(['keys' => [appleVerifierTestKey()['jwk']]])]);
    $segments = explode('.', appleVerifierTestToken());
    $segments[2] = JWT::urlsafeB64Encode(str_repeat('x', 256));
    expect(fn () => (new AppleTokenVerifier)->verify(implode('.', $segments)))
        ->toThrow(InvalidAppleTokenException::class);
});

it('rejects malformed tokens and symmetric algorithms without fetching keys', function ($token) {
    expect(fn () => (new AppleTokenVerifier)->verify($token))->toThrow(InvalidAppleTokenException::class);
    Http::assertNothingSent();
})->with([
    'malformed' => 'invalid',
    'wrong algorithm' => fn () => JWT::encode(['sub' => '123'], str_repeat('s', 64), 'HS256', 'test-key'),
]);

it('fails closed when all configured audiences are empty', function () {
    config(['services.apple.service_id' => null, 'services.apple.bundle_id' => '']);
    try {
        (new AppleTokenVerifier)->verify(appleVerifierTestToken());
        $this->fail('Unconfigured Apple Sign In must fail.');
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(503)->and($e->getMessage())->toBe('Apple Sign In is not configured.');
    }
    Http::assertNothingSent();
});

it('refreshes rotated keys and limits unknown key refreshes', function () {
    Cache::put('auth.apple.jwks', ['keys' => [appleVerifierTestKey('old-key')['jwk']]], 3600);
    Http::fake(['appleid.apple.com/auth/keys' => Http::response(['keys' => [appleVerifierTestKey('new-key')['jwk']]])]);
    $verifier = new AppleTokenVerifier;
    expect($verifier->verify(appleVerifierTestToken([], 'new-key'))['sub'])->toBe('apple-123');
    expect(fn () => $verifier->verify(appleVerifierTestToken([], 'unknown-key')))->toThrow(InvalidAppleTokenException::class);
    Http::assertSentCount(1);
});

it('reports key retrieval failures as service unavailable', function ($response) {
    Http::fake(['appleid.apple.com/auth/keys' => $response]);
    try {
        (new AppleTokenVerifier)->verify(appleVerifierTestToken());
        $this->fail('Invalid provider keys must fail.');
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(503);
    }
    expect(Cache::has('auth.apple.jwks'))->toBeFalse();
})->with([
    'http failure' => fn () => Http::response([], 500),
    'malformed keys' => fn () => Http::response(['keys' => []]),
    'missing keys' => fn () => Http::response(['error' => 'invalid']),
    'connection failure' => fn () => Http::failedConnection(),
]);

it('accepts either apple audience when the other is empty', function ($serviceId, $bundleId, $audience) {
    config(['services.apple.service_id' => $serviceId, 'services.apple.bundle_id' => $bundleId]);
    Http::fake(['appleid.apple.com/auth/keys' => Http::response(['keys' => [appleVerifierTestKey()['jwk']]])]);
    expect((new AppleTokenVerifier)->verify(appleVerifierTestToken(['aud' => $audience]))['sub'])->toBe('apple-123');
})->with([
    [null, 'bundle-id', 'bundle-id'],
    ['service-id', null, 'service-id'],
]);

it('normalizes apple boolean and string claims strictly', function ($verified, $private, $expectedVerified, $expectedPrivate) {
    Http::fake(['appleid.apple.com/auth/keys' => Http::response(['keys' => [appleVerifierTestKey()['jwk']]])]);
    $claims = (new AppleTokenVerifier)->verify(appleVerifierTestToken([
        'email_verified' => $verified, 'is_private_email' => $private,
    ]));
    expect($claims['email_verified'])->toBe($expectedVerified)->and($claims['is_private_email'])->toBe($expectedPrivate);
})->with([
    ['true', 'true', true, true],
    ['false', 'false', false, false],
    [true, false, true, false],
    [false, true, false, true],
]);

it('recognizes relay email even without a private flag', function () {
    Http::fake(['appleid.apple.com/auth/keys' => Http::response(['keys' => [appleVerifierTestKey()['jwk']]])]);
    expect((new AppleTokenVerifier)->verify(appleVerifierTestToken([
        'email' => 'relay@PRIVATERELAY.APPLEID.COM', 'is_private_email' => false,
    ]))['is_private_email'])->toBeTrue();
});
