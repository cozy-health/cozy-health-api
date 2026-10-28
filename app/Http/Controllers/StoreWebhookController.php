<?php
namespace App\Http\Controllers;
use App\Http\Controllers\Api\V1\StoreBillingController;
use App\Models\User;
use App\Services\StorePurchaseVerifier;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
class StoreWebhookController extends Controller
{
    public function __invoke(Request $request, string $platform, StorePurchaseVerifier $verifier, StoreBillingController $billing)
    {
        abort_unless(config('launch.billing_enabled'), 503, 'Store billing is disabled.');
        abort_if(strlen($request->getContent()) > 32768, 413);
        if ($platform === 'apple') {
            $data = $request->validate(['signedPayload' => ['required', 'string', 'max:32000']]);
            $notification = $this->applePayload($data['signedPayload']);
            abort_unless(($notification['data']->bundleId ?? '') === config('launch.apple.bundle_id'), 403);
            if (!isset($notification['data']->signedTransactionInfo)) return response()->noContent();
            $transaction = $this->applePayload($notification['data']->signedTransactionInfo);
            $reference = $transaction['transactionId'] ?? null;
        } else {
            $this->googleIdentity($request);
            $data = $request->validate(['message.data' => ['required', 'string', 'max:16000']]);
            $decoded = base64_decode($data['message']['data'], true);
            abort_unless($decoded !== false, 422);
            $notification = json_decode($decoded, true, flags: JSON_THROW_ON_ERROR);
            abort_unless(($notification['packageName'] ?? '') === config('launch.google.package_name'), 403);
            $reference = $notification['subscriptionNotification']['purchaseToken'] ?? $notification['voidedPurchaseNotification']['purchaseToken'] ?? null;
        }
        if (!$reference) return response()->noContent();
        // Notification fields never directly update access; both stores are queried again.
        $purchase = $verifier->verify($platform, (string) $reference);
        $account = DB::table('store_accounts')->where('account_token', strtolower($purchase['account_token']))->first();
        if (!$account || !($user = User::find($account->user_id))) return response()->noContent();
        $internal = Request::create('/internal/store-event', 'POST', ['platform' => $platform, 'reference' => (string) $reference]);
        $internal->setUserResolver(fn () => $user);
        $billing->verify($internal, $verifier);
        return response()->noContent();
    }
    private function applePayload(string $jws): array
    {
        $root = config('launch.apple.root_ca');
        abort_unless($root && is_file($root), 503, 'Apple trust anchor is not configured.');
        $parts = explode('.', $jws); abort_unless(count($parts) === 3, 403);
        $header = json_decode(JWT::urlsafeB64Decode($parts[0]), true, flags: JSON_THROW_ON_ERROR);
        abort_unless(($header['alg'] ?? '') === 'ES256' && count($header['x5c'] ?? []) >= 2, 403);
        $pem = fn ($cert) => "-----BEGIN CERTIFICATE-----\n".chunk_split($cert, 64, "\n")."-----END CERTIFICATE-----\n";
        $leaf = $pem($header['x5c'][0]);
        $extensions = openssl_x509_parse($leaf)['extensions'] ?? [];
        abort_unless(array_key_exists('1.2.840.113635.100.6.11.1', $extensions), 403);
        $chain = tempnam(storage_path('app/private'), 'apple-chain-');
        abort_unless($chain, 503);
        try {
            file_put_contents($chain, implode('', array_map($pem, array_slice($header['x5c'], 1))));
            abort_unless(openssl_x509_checkpurpose($leaf, X509_PURPOSE_ANY, [$root], $chain) === true, 403);
        } finally { @unlink($chain); }
        $key = openssl_pkey_get_public($leaf); abort_unless($key, 403);
        return (array) JWT::decode($jws, new Key(openssl_pkey_get_details($key)['key'], 'ES256'));
    }
    private function googleIdentity(Request $request): void
    {
        $audience = config('launch.google.webhook_audience'); $email = config('launch.google.webhook_email');
        abort_unless($audience && $email, 503, 'Google notification identity is not configured.');
        $token = $request->bearerToken(); abort_unless($token && strlen($token) < 16000, 401);
        $parts = explode('.', $token); abort_unless(count($parts) === 3, 401);
        $header = json_decode(JWT::urlsafeB64Decode($parts[0]), true, flags: JSON_THROW_ON_ERROR);
        $certs = Cache::remember('store:google:oidc-certs', 1800, fn () => Http::timeout(10)->get('https://www.googleapis.com/oauth2/v1/certs')->throw()->json());
        abort_unless(($header['alg'] ?? '') === 'RS256' && isset($certs[$header['kid'] ?? '']), 401);
        $claims = JWT::decode($token, new Key($certs[$header['kid']], 'RS256'));
        abort_unless(in_array($claims->iss ?? '', ['accounts.google.com', 'https://accounts.google.com'], true) && ($claims->aud ?? '') === $audience && ($claims->email ?? '') === $email && ($claims->email_verified ?? false) === true, 401);
    }
}
