<?php
namespace Tests\Feature;
use App\Services\StorePurchaseVerifier;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
class StoreVerificationTest extends TestCase
{
    public function test_google_store_states_and_trials_are_not_confused_with_successful_payments(): void
    {
        config(['launch.billing_enabled' => true, 'launch.google.package_name' => 'org.cozyhealth.app', 'launch.google.credentials' => json_encode(['client_email' => 'store-state@example.test'])]);
        \Illuminate\Support\Facades\Cache::put('store:google:'.hash('sha256', 'store-state@example.test'), 'fake-access-token', 300);
        foreach (['ACTIVE' => ['active', 'trial'], 'CANCELED' => ['active', 'canceled'], 'IN_GRACE_PERIOD' => ['active', 'grace_period'], 'ON_HOLD' => ['expired', 'on_hold'], 'PAUSED' => ['expired', 'paused'], 'PENDING' => ['expired', 'pending'], 'EXPIRED' => ['expired', 'expired']] as $state => [$access, $label]) {
            Http::swap(new \Illuminate\Http\Client\Factory()); Http::preventStrayRequests();
            Http::fake(['*subscriptionsv2/tokens/state-test' => Http::response([
                'externalAccountIdentifiers' => ['obfuscatedExternalAccountId' => 'owner'], 'subscriptionState' => 'SUBSCRIPTION_STATE_'.$state,
                'testPurchase' => [], 'lineItems' => [['productId' => 'premium.monthly', 'expiryTime' => now()->addMonth()->toIso8601String(),
                    'autoRenewingPlan' => ['autoRenewEnabled' => $state !== 'CANCELED'], 'offerPhase' => ['freeTrial' => []]]],
            ])]);
            $purchase = app(StorePurchaseVerifier::class)->verify('google', 'state-test');
            $this->assertSame($access, $purchase['status'], $state);
            $this->assertSame($label, $purchase['plan_details']['state'], $state);
            $this->assertSame('sandbox', $purchase['plan_details']['environment']);
            $this->assertArrayNotHasKey('price', $purchase['plan_details']);
        }
    }

    public function test_apple_renewal_binding_cancellation_and_grace_use_verified_signed_store_details(): void
    {
        $material = \Tests\Support\AppleSigningKey::material();
        $key = openssl_pkey_get_private($material['private']);
        $options = ['config' => __DIR__.'/../Fixtures/openssl.cnf'];
        $csr = openssl_csr_new(['commonName' => 'Isolated Store Test'], $key, $options);
        $cert = openssl_csr_sign($csr, null, $key, 1, $options);
        openssl_x509_export($cert, $pem);
        $encoded = preg_replace('/-----[^-]+-----|\s+/', '', $pem);
        $sign = fn ($claims) => \Firebase\JWT\JWT::encode($claims, $material['private'], 'ES256', null, ['x5c' => [$encoded]]);
        config(['launch.billing_enabled' => true, 'launch.apple' => ['issuer_id' => 'test-issuer', 'key_id' => 'test-key',
            'private_key' => $material['private'], 'bundle_id' => 'org.cozyhealth.app', 'sandbox' => true]]);
        foreach ([['status' => 1, 'auto' => 0, 'state' => 'canceled'], ['status' => 4, 'auto' => 1, 'state' => 'grace_period']] as $case) {
            Http::swap(new \Illuminate\Http\Client\Factory()); Http::preventStrayRequests();
            $transaction = ['bundleId' => 'org.cozyhealth.app', 'environment' => 'Sandbox', 'transactionId' => '123', 'originalTransactionId' => '100',
                'appAccountToken' => 'owner', 'productId' => 'premium.monthly', 'expiresDate' => ($case['status'] === 4 ? now()->subDay() : now()->addMonth())->getTimestampMs()];
            $renewal = ['originalTransactionId' => '100', 'environment' => 'Sandbox', 'autoRenewStatus' => $case['auto'], 'gracePeriodExpiresDate' => now()->addDays(3)->getTimestampMs()];
            Http::fake([
                '*transactions/123' => Http::response(['signedTransactionInfo' => $sign($transaction)]),
                '*subscriptions/123' => Http::response(['data' => [['lastTransactions' => [['originalTransactionId' => '100', 'status' => $case['status'],
                    'signedTransactionInfo' => $sign($transaction), 'signedRenewalInfo' => $sign($renewal)]]]]]),
            ]);
            $purchase = app(StorePurchaseVerifier::class)->verify('apple', '123');
            $this->assertSame('active', $purchase['status']);
            $this->assertSame($case['state'], $purchase['plan_details']['state']);
            $this->assertSame((bool) $case['auto'], $purchase['plan_details']['auto_renews']);
            $this->assertTrue($purchase['expires_at']->isFuture());
        }
        $renewal['originalTransactionId'] = 'different-subscription';
        Http::swap(new \Illuminate\Http\Client\Factory()); Http::preventStrayRequests();
        Http::fake([
            '*transactions/123' => Http::response(['signedTransactionInfo' => $sign($transaction)]),
            '*subscriptions/123' => Http::response(['data' => [['lastTransactions' => [['originalTransactionId' => '100', 'status' => 4,
                'signedTransactionInfo' => $sign($transaction), 'signedRenewalInfo' => $sign($renewal)]]]]]),
        ]);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(StorePurchaseVerifier::class)->verify('apple', '123');
    }

    public function test_google_verification_queries_the_store_and_acknowledges_only_verified_pending_purchases(): void
    {
        config(['launch.billing_enabled' => true, 'launch.google.package_name' => 'org.cozyhealth.app', 'launch.google.credentials' => json_encode(['client_email' => 'store-test@example.test'])]);
        \Illuminate\Support\Facades\Cache::put('store:google:'.hash('sha256', 'store-test@example.test'), 'access-token', 300);
        Http::fake([
            '*subscriptionsv2/tokens/test-purchase' => Http::response(['externalAccountIdentifiers' => ['obfuscatedExternalAccountId' => 'account-id'], 'subscriptionState' => 'SUBSCRIPTION_STATE_ACTIVE', 'acknowledgementState' => 'ACKNOWLEDGEMENT_STATE_PENDING', 'lineItems' => [['productId' => 'premium.monthly', 'expiryTime' => now()->addMonth()->toIso8601String()]]]),
            '*:acknowledge' => Http::response([], 200),
        ]);
        $verifier = app(StorePurchaseVerifier::class); $purchase = $verifier->verify('google', 'test-purchase');
        $this->assertSame('active', $purchase['status']); $this->assertSame('account-id', $purchase['account_token']);
        $verifier->acknowledge($purchase);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), ':acknowledge') && $request->body() === '{}');
    }
}
