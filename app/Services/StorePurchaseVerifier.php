<?php
namespace App\Services;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class StorePurchaseVerifier
{
    public function verify(string $platform, string $reference): array
    {
        abort_unless(config('launch.billing_enabled'), 503, 'Store purchasing is not activated.');
        return $platform === 'apple' ? $this->apple($reference) : $this->google($reference);
    }
    private function apple(string $transactionId): array
    {
        $config = config('launch.apple');
        abort_unless($config['issuer_id'] && $config['key_id'] && $config['private_key'] && $config['bundle_id'], 503, 'App Store credentials are not configured.');
        abort_unless(preg_match('/^[0-9]{1,40}$/', $transactionId), 422, 'Invalid transaction identifier.');
        $bearer = JWT::encode(['iss' => $config['issuer_id'], 'iat' => time(), 'exp' => time() + 300, 'aud' => 'appstoreconnect-v1', 'bid' => $config['bundle_id']], str_replace('\\n', "\n", $config['private_key']), 'ES256', $config['key_id']);
        $host = $config['sandbox'] ? 'https://api.storekit-sandbox.apple.com' : 'https://api.storekit.apple.com';
        // Never decode a JWS supplied by a client or webhook. Obtain it directly from Apple's authenticated TLS endpoint.
        $response = Http::withToken($bearer)->timeout(20)->get($host.'/inApps/v1/transactions/'.$transactionId);
        abort_unless($response->successful(), 422, 'The store could not verify this purchase.');
        $jws = $response->json('signedTransactionInfo');
        abort_unless(is_string($jws) && count(explode('.', $jws)) === 3, 422, 'Invalid store response.');
        $header = json_decode(JWT::urlsafeB64Decode(explode('.', $jws)[0]), true, flags: JSON_THROW_ON_ERROR);
        abort_unless(($header['alg'] ?? null) === 'ES256' && isset($header['x5c'][0]), 422, 'Invalid store signature.');
        $cert = "-----BEGIN CERTIFICATE-----\n".chunk_split($header['x5c'][0], 64, "\n")."-----END CERTIFICATE-----\n";
        $publicKey = openssl_pkey_get_public($cert);
        abort_unless($publicKey, 422, 'Invalid store signing key.');
        $details = openssl_pkey_get_details($publicKey);
        $transaction = (array) JWT::decode($jws, new Key($details['key'], 'ES256'));
        abort_unless(($transaction['bundleId'] ?? null) === $config['bundle_id'] && ($transaction['environment'] ?? null) === ($config['sandbox'] ? 'Sandbox' : 'Production'), 422, 'Purchase belongs to another app or environment.');
        abort_unless(($transaction['transactionId'] ?? null) === $transactionId && isset($transaction['originalTransactionId'], $transaction['expiresDate'], $transaction['appAccountToken']), 422, 'The purchase is missing required account binding.');
        // Query current subscription state, so old transactions cannot roll back a renewal or hide a refund.
        $states = Http::withToken($bearer)->timeout(20)->get($host.'/inApps/v1/subscriptions/'.$transactionId);
        abort_unless($states->successful(), 503, 'Current store subscription state is unavailable.');
        $current = [];
        foreach ($states->json('data') ?? [] as $group) foreach ($group['lastTransactions'] ?? [] as $entry) {
            if (($entry['originalTransactionId'] ?? null) !== $transaction['originalTransactionId']) continue;
            $signed = $entry['signedTransactionInfo'] ?? '';
            $parts = explode('.', $signed);
            abort_unless(count($parts) === 3, 422, 'Invalid store subscription response.');
            $currentHeader = json_decode(JWT::urlsafeB64Decode($parts[0]), true, flags: JSON_THROW_ON_ERROR);
            abort_unless(($currentHeader['alg'] ?? '') === 'ES256' && isset($currentHeader['x5c'][0]), 422);
            $currentCert = "-----BEGIN CERTIFICATE-----\n".chunk_split($currentHeader['x5c'][0], 64, "\n")."-----END CERTIFICATE-----\n";
            $key = openssl_pkey_get_public($currentCert);
            abort_unless($key, 422);
            $decoded = (array) JWT::decode($signed, new Key(openssl_pkey_get_details($key)['key'], 'ES256'));
            abort_unless(($decoded['bundleId'] ?? '') === $config['bundle_id'] && ($decoded['environment'] ?? '') === ($config['sandbox'] ? 'Sandbox' : 'Production') && ($decoded['appAccountToken'] ?? '') === $transaction['appAccountToken'], 422);
            $renewal = isset($entry['signedRenewalInfo']) ? $this->appleRenewal($entry['signedRenewalInfo']) : [];
            abort_unless(!$renewal || (($renewal['originalTransactionId'] ?? '') === $decoded['originalTransactionId']
                && ($renewal['environment'] ?? $decoded['environment']) === $decoded['environment']), 422, 'Invalid store renewal binding.');
            $current[] = [...$decoded, 'subscription_status' => $entry['status'], 'renewal_info' => $renewal];
        }
        $transaction = collect($current)->sortByDesc('expiresDate')->first();
        abort_unless($transaction, 422, 'No current subscription was returned.');
        $expires = Carbon::createFromTimestampMs($transaction['expiresDate']);
        $renewal = $transaction['renewal_info'];
        if ($transaction['subscription_status'] === 4 && isset($renewal['gracePeriodExpiresDate'])) $expires = Carbon::createFromTimestampMs($renewal['gracePeriodExpiresDate']);
        $auto = match ($renewal['autoRenewStatus'] ?? null) { 1 => true, 0 => false, default => null };
        $state = isset($transaction['revocationDate']) ? 'revoked' : match ($transaction['subscription_status']) { 1 => 'active', 2 => 'expired', 3 => 'billing_retry', 4 => 'grace_period', 5 => 'revoked', default => 'unknown' };
        if (in_array($state, ['active', 'grace_period'], true) && !$expires->isFuture()) $state = 'expired';
        if ($state === 'active' && $auto === false) $state = 'canceled';
        $trial = isset($transaction['offerDiscountType']) ? $transaction['offerDiscountType'] === 'FREE_TRIAL' : (isset($transaction['offerType']) ? null : false);
        return ['reference' => (string) $transaction['transactionId'], 'identity' => (string) $transaction['originalTransactionId'], 'product_id' => $transaction['productId'], 'account_token' => strtolower($transaction['appAccountToken']), 'expires_at' => $expires, 'status' => $state === 'revoked' ? 'revoked' : (in_array($state, ['active', 'canceled', 'grace_period'], true) ? 'active' : 'expired'),
            'plan_details' => $this->planDetails('apple', $state, $auto, $trial, $config['sandbox'] ? 'sandbox' : 'production', $expires)];
    }
    private function appleRenewal(string $signed): array
    {
        $parts = explode('.', $signed);
        abort_unless(count($parts) === 3, 422, 'Invalid store renewal response.');
        $header = json_decode(JWT::urlsafeB64Decode($parts[0]), true, flags: JSON_THROW_ON_ERROR);
        abort_unless(($header['alg'] ?? '') === 'ES256' && isset($header['x5c'][0]), 422, 'Invalid store renewal signature.');
        $cert = "-----BEGIN CERTIFICATE-----\n".chunk_split($header['x5c'][0], 64, "\n")."-----END CERTIFICATE-----\n";
        $key = openssl_pkey_get_public($cert);
        abort_unless($key, 422, 'Invalid store renewal key.');
        return (array) JWT::decode($signed, new Key(openssl_pkey_get_details($key)['key'], 'ES256'));
    }
    private function planDetails(string $platform, string $state, ?bool $auto, ?bool $trial, string $environment, Carbon $expires): array
    {
        return ['platform' => $platform, 'state' => $state === 'active' && $trial === true ? 'trial' : $state,
            'auto_renews' => $auto, 'is_trial' => $trial, 'environment' => $environment, 'expires_at' => $expires->toIso8601String()];
    }
    private function google(string $purchaseToken): array
    {
        $package = config('launch.google.package_name');
        abort_unless($package && config('launch.google.credentials'), 503, 'Google Play credentials are not configured.');
        $credentials = json_decode(config('launch.google.credentials'), true, flags: JSON_THROW_ON_ERROR);
        $bearer = Cache::remember('store:google:'.hash('sha256', $credentials['client_email']), 3000, function () use ($credentials) {
            $assertion = JWT::encode(['iss' => $credentials['client_email'], 'scope' => 'https://www.googleapis.com/auth/androidpublisher', 'aud' => 'https://oauth2.googleapis.com/token', 'iat' => time(), 'exp' => time() + 3600], $credentials['private_key'], 'RS256');
            $response = Http::asForm()->timeout(15)->post('https://oauth2.googleapis.com/token', ['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $assertion]);
            abort_unless($response->successful() && is_string($response->json('access_token')), 503, 'Store authorization is unavailable.');
            return $response->json('access_token');
        });
        $base = 'https://androidpublisher.googleapis.com/androidpublisher/v3/applications/'.rawurlencode($package);
        $response = Http::withToken($bearer)->timeout(20)->get($base.'/purchases/subscriptionsv2/tokens/'.rawurlencode($purchaseToken));
        abort_unless($response->successful(), 422, 'The store could not verify this purchase.');
        $data = $response->json();
        $items = collect($data['lineItems'] ?? [])->filter(fn ($item) => isset($item['expiryTime'], $item['productId']))->sortByDesc('expiryTime');
        $item = $items->first();
        abort_unless($item && isset($data['externalAccountIdentifiers']['obfuscatedExternalAccountId']), 422, 'The purchase is missing required account binding.');
        $expires = Carbon::parse($item['expiryTime']);
        $active = in_array($data['subscriptionState'] ?? '', ['SUBSCRIPTION_STATE_ACTIVE', 'SUBSCRIPTION_STATE_IN_GRACE_PERIOD', 'SUBSCRIPTION_STATE_CANCELED'], true) && $expires->isFuture();
        $state = match ($data['subscriptionState'] ?? '') {
            'SUBSCRIPTION_STATE_ACTIVE' => 'active', 'SUBSCRIPTION_STATE_IN_GRACE_PERIOD' => 'grace_period',
            'SUBSCRIPTION_STATE_CANCELED' => 'canceled', 'SUBSCRIPTION_STATE_ON_HOLD' => 'on_hold',
            'SUBSCRIPTION_STATE_PAUSED' => 'paused', 'SUBSCRIPTION_STATE_PENDING' => 'pending',
            'SUBSCRIPTION_STATE_EXPIRED', 'SUBSCRIPTION_STATE_PENDING_PURCHASE_CANCELED' => 'expired', default => 'unknown',
        };
        if (in_array($state, ['active', 'grace_period', 'canceled'], true) && !$expires->isFuture()) $state = 'expired';
        $auto = $item['autoRenewingPlan']['autoRenewEnabled'] ?? null;
        $auto = is_bool($auto) ? $auto : null;
        if ($state === 'canceled') $auto = false;
        if ($state === 'active' && $auto === false) $state = 'canceled';
        $trial = isset($item['offerPhase']['freeTrial']) ? true : (isset($item['offerPhase']['basePrice']) || isset($item['offerPhase']['introductoryPrice']) ? false : null);
        return ['reference' => $purchaseToken, 'identity' => $purchaseToken, 'product_id' => $item['productId'], 'account_token' => $data['externalAccountIdentifiers']['obfuscatedExternalAccountId'], 'expires_at' => $expires, 'status' => $active ? 'active' : 'expired', 'acknowledgement_required' => $active && ($data['acknowledgementState'] ?? '') === 'ACKNOWLEDGEMENT_STATE_PENDING', 'google_bearer' => $bearer,
            'plan_details' => $this->planDetails('google', $state, $auto, $trial, isset($data['testPurchase']) ? 'sandbox' : 'production', $expires)];
    }
    public function acknowledge(array $purchase): void
    {
        if (!($purchase['acknowledgement_required'] ?? false)) return;
        $url = 'https://androidpublisher.googleapis.com/androidpublisher/v3/applications/'.rawurlencode(config('launch.google.package_name')).'/purchases/subscriptions/'.rawurlencode($purchase['product_id']).'/tokens/'.rawurlencode($purchase['reference']).':acknowledge';
        abort_unless(Http::withToken($purchase['google_bearer'])->withBody('{}', 'application/json')->timeout(20)->post($url)->successful(), 503, 'Purchase verification is saved; store acknowledgement needs retry.');
    }
}
