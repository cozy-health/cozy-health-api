<?php
namespace Tests\Feature;
use App\Services\StorePurchaseVerifier;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
class StoreVerificationTest extends TestCase
{
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
