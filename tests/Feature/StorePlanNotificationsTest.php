<?php

use App\Jobs\SendStorePlanEmail;
use App\Mail\StorePlanStatusMail;
use App\Models\User;
use App\Services\StorePurchaseVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Bus, DB, Mail, Queue};
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    Mail::fake(); $this->realQueue = app('queue'); Queue::fake();
    config(['launch.billing_enabled' => true, 'store-plans.emails_enabled' => true,
        'launch.products' => ['premium.monthly' => ['platform' => 'apple', 'tier' => 'premium']]]);
    $this->owner = User::factory()->create(); Sanctum::actingAs($this->owner);
    DB::table('store_accounts')->insert(['user_id' => $this->owner->id, 'account_token' => 'bound-account']);
    $this->expires = now()->addMonth()->startOfSecond();
    $this->purchase = fn (string $state = 'active', $expires = null) => [
        'reference' => 'private-store-reference', 'identity' => 'subscription-identity', 'account_token' => 'bound-account',
        'product_id' => 'premium.monthly', 'status' => in_array($state, ['active', 'trial', 'canceled', 'grace_period']) ? 'active' : ($state === 'revoked' ? 'revoked' : 'expired'),
        'expires_at' => $expires ?? $this->expires, 'plan_details' => ['platform' => 'apple', 'state' => $state,
            'auto_renews' => $state !== 'canceled', 'is_trial' => $state === 'trial', 'environment' => 'sandbox',
            'expires_at' => ($expires ?? $this->expires)->toIso8601String()],
    ];
    $this->payload = ['platform' => 'apple', 'reference' => 'client-reference'];
});

function fakePlanStore(array ...$purchases): void
{
    $verifier = Mockery::mock(StorePurchaseVerifier::class);
    $verifier->shouldReceive('verify')->andReturn(...$purchases);
    $verifier->shouldReceive('acknowledge')->andReturnNull();
    app()->instance(StorePurchaseVerifier::class, $verifier);
}

it('compares plan snapshots independently of database JSON field order', function () {
    $details = ($this->purchase)()['plan_details'];
    expect(\App\Services\StorePlanNotifications::fingerprint($details))
        ->toBe(\App\Services\StorePlanNotifications::fingerprint(array_reverse($details, true)));
});

it('queues one encrypted plan-status job for repeated verification without creating invoices', function () {
    fakePlanStore(($this->purchase)());
    $this->postJson('/api/v1/subscription/verify-receipt', $this->payload)->assertOk();
    $this->postJson('/api/v1/subscription/verify-receipt', $this->payload)->assertOk();
    $this->assertDatabaseCount('store_plan_notifications', 1);
    Queue::assertPushed(SendStorePlanEmail::class, 1);
    $row = DB::table('store_plan_notifications')->first();
    expect($row->ready_at)->not->toBeNull();
    expect($row->queued_at)->not->toBeNull();
    expect($row->details)->not->toContain('private-store-reference', $this->owner->email, 'price', 'VAT');
    $job = new SendStorePlanEmail($row->id); $job->handle(); $job->handle();
    Mail::assertSent(StorePlanStatusMail::class, 1);
    Mail::assertSent(StorePlanStatusMail::class, fn ($mail) => $mail->hasTo($this->owner->email)
        && str_contains(implode(' ', $mail->paragraphs), 'not request another payment'));
    $this->assertDatabaseHas('store_plan_notifications', ['id' => $row->id, 'status' => 'sent']);
    expect($job)->toBeInstanceOf(\Illuminate\Contracts\Queue\ShouldBeEncrypted::class);
});

it('retains pending notification work without breaking access if queue insertion fails', function () {
    fakePlanStore(($this->purchase)());
    Bus::shouldReceive('dispatch')->andThrow(new RuntimeException('Synthetic queue outage'));
    $this->postJson('/api/v1/subscription/verify-receipt', $this->payload)->assertOk();
    $this->assertDatabaseHas('subscription_statuses', ['user_id' => $this->owner->id, 'status' => 'active']);
    $this->assertDatabaseHas('store_plan_notifications', ['status' => 'pending', 'queued_at' => null]);
});

it('commits the database queue payload and outbox marker with an encrypted identifier-only job', function () {
    Queue::swap($this->realQueue); fakePlanStore(($this->purchase)());
    $this->postJson('/api/v1/subscription/verify-receipt', $this->payload)->assertOk();
    $row = DB::table('store_plan_notifications')->first();
    $payload = json_decode(DB::table('jobs')->where('queue', 'email')->value('payload'), true, flags: JSON_THROW_ON_ERROR);
    expect($row->queued_at)->not->toBeNull();
    expect($payload['data']['command'])->not->toContain($row->id, 'private-store-reference', $this->owner->email);
    $job = \Illuminate\Support\Facades\Crypt::decrypt($payload['data']['command']);
    expect($job)->toContain($row->id)->not->toContain('private-store-reference', $this->owner->email);
});

it('waits for store acknowledgement and recovers on the same verification retry', function () {
    $attempt = 0; $purchase = ($this->purchase)();
    $verifier = Mockery::mock(StorePurchaseVerifier::class);
    $verifier->shouldReceive('verify')->andReturn($purchase);
    $verifier->shouldReceive('acknowledge')->andReturnUsing(function () use (&$attempt) { if (++$attempt === 1) throw new RuntimeException('Synthetic acknowledgement outage'); });
    app()->instance(StorePurchaseVerifier::class, $verifier);
    $this->postJson('/api/v1/subscription/verify-receipt', $this->payload)->assertStatus(503);
    Queue::assertNotPushed(SendStorePlanEmail::class);
    $this->assertDatabaseHas('store_plan_notifications', ['ready_at' => null]);
    $this->postJson('/api/v1/subscription/verify-receipt', $this->payload)->assertOk();
    Queue::assertPushed(SendStorePlanEmail::class, 1);
    $this->assertDatabaseCount('store_plan_notifications', 1);
});

it('suppresses stale queued states after an extension and sends only the current state', function () {
    fakePlanStore(($this->purchase)(), ($this->purchase)('active', $this->expires->copy()->addMonth()));
    $this->postJson('/api/v1/subscription/verify-receipt', $this->payload)->assertOk();
    $this->postJson('/api/v1/subscription/verify-receipt', $this->payload)->assertOk();
    $rows = DB::table('store_plan_notifications')->orderBy('created_at')->get();
    expect($rows)->toHaveCount(2);
    foreach ($rows as $row) (new SendStorePlanEmail($row->id))->handle();
    Mail::assertSent(StorePlanStatusMail::class, 1);
    expect(DB::table('store_plan_notifications')->where('status', 'obsolete')->count())->toBe(1);
});

it('does not send to a changed email or after account deletion', function (string $change) {
    fakePlanStore(($this->purchase)());
    $this->postJson('/api/v1/subscription/verify-receipt', $this->payload)->assertOk();
    $row = DB::table('store_plan_notifications')->first();
    if ($change === 'email') $this->owner->forceFill(['email' => 'different@example.test', 'email_verified_at' => now()])->save();
    else $this->owner->delete();
    (new SendStorePlanEmail($row->id))->handle();
    Mail::assertNothingOutgoing();
})->with(['email', 'deleted']);

it('does not send notifications while billing or plan emails are disabled', function (string $flag) {
    config([$flag => false]); fakePlanStore(($this->purchase)());
    $this->postJson('/api/v1/subscription/verify-receipt', $this->payload)->assertStatus($flag === 'launch.billing_enabled' ? 503 : 200);
    $this->assertDatabaseCount('store_plan_notifications', 0);
    Queue::assertNotPushed(SendStorePlanEmail::class);
})->with(['launch.billing_enabled', 'store-plans.emails_enabled']);

it('rejects a purchase bound to another account without queuing notices', function () {
    $purchase = ($this->purchase)(); $purchase['account_token'] = 'another-account'; fakePlanStore($purchase);
    $this->postJson('/api/v1/subscription/verify-receipt', $this->payload)->assertForbidden();
    $this->assertDatabaseCount('store_plan_notifications', 0);
});

it('exposes only owned store management details with the current subscription', function () {
    fakePlanStore(($this->purchase)('canceled'));
    $this->postJson('/api/v1/subscription/verify-receipt', $this->payload)->assertOk();
    $this->getJson('/api/v1/subscription')->assertOk()->assertJsonPath('data.store_state', 'canceled')
        ->assertJsonPath('data.auto_renews', false)->assertJsonPath('data.store_management.store', 'Apple App Store')
        ->assertJsonPath('data.store_management.manage_url', 'https://apps.apple.com/account/subscriptions');
    Sanctum::actingAs(User::factory()->create());
    $this->getJson('/api/v1/subscription')->assertOk()->assertJsonMissingPath('data.store_management');
});

it('marks delayed active notices obsolete when confirmed access has expired', function () {
    $this->freezeTime(); fakePlanStore(($this->purchase)());
    $this->postJson('/api/v1/subscription/verify-receipt', $this->payload)->assertOk();
    $this->travelTo($this->expires);
    (new SendStorePlanEmail(DB::table('store_plan_notifications')->value('id')))->handle();
    Mail::assertNothingOutgoing();
    $this->assertDatabaseHas('store_plan_notifications', ['status' => 'obsolete']);
});

it('preserves payment-hold state in the current-status response without granting access', function () {
    fakePlanStore(($this->purchase)('on_hold'));
    $this->postJson('/api/v1/subscription/verify-receipt', $this->payload)->assertOk();
    $this->getJson('/api/v1/subscription')->assertOk()->assertJsonPath('data.status', 'expired')->assertJsonPath('data.store_state', 'on_hold');
});

it('permits an operator retry of a failed email without changing subscription access', function () {
    fakePlanStore(($this->purchase)());
    $this->postJson('/api/v1/subscription/verify-receipt', $this->payload)->assertOk();
    $row = DB::table('store_plan_notifications')->first();
    $job = new SendStorePlanEmail($row->id); $job->failed(new RuntimeException('Synthetic exhausted delivery'));
    $job->handle();
    Mail::assertSent(StorePlanStatusMail::class, 1);
    $this->assertDatabaseHas('store_plan_notifications', ['id' => $row->id, 'status' => 'sent']);
    $this->assertDatabaseHas('subscription_statuses', ['user_id' => $this->owner->id, 'status' => 'active']);
});

it('still reconciles annual plans last checked more than sixty days ago', function () {
    fakePlanStore(($this->purchase)());
    $this->postJson('/api/v1/subscription/verify-receipt', $this->payload)->assertOk();
    DB::table('store_purchases')->update(['updated_at' => now()->subDays(90)]);
    $verifier = Mockery::mock(StorePurchaseVerifier::class);
    $verifier->shouldReceive('verify')->once()->andReturn(($this->purchase)());
    $verifier->shouldReceive('acknowledge')->once();
    app()->instance(StorePurchaseVerifier::class, $verifier);
    $this->artisan('stores:reconcile')->assertSuccessful();
});

it('keeps free trials distinct and never describes them as paid purchases', function () {
    fakePlanStore(($this->purchase)('trial'));
    $this->postJson('/api/v1/subscription/verify-receipt', $this->payload)->assertOk();
    (new SendStorePlanEmail(DB::table('store_plan_notifications')->value('id')))->handle();
    Mail::assertSent(StorePlanStatusMail::class, fn ($mail) => $mail->heading === 'Your Cozy trial is active'
        && str_contains(implode(' ', $mail->paragraphs), 'not a payment receipt'));
});

it('versions cancel-and-reactivate transitions and suppresses previous matching states', function () {
    fakePlanStore(($this->purchase)(), ($this->purchase)('canceled'), ($this->purchase)(), ($this->purchase)());
    for ($i = 0; $i < 4; $i++) $this->postJson('/api/v1/subscription/verify-receipt', $this->payload)->assertOk();
    $this->assertDatabaseCount('store_plan_notifications', 3);
    $this->assertDatabaseHas('store_purchases', ['plan_version' => 3]);
    foreach (DB::table('store_plan_notifications')->orderBy('plan_version')->get() as $row) (new SendStorePlanEmail($row->id))->handle();
    Mail::assertSent(StorePlanStatusMail::class, 1);
    expect(DB::table('store_plan_notifications')->where('status', 'obsolete')->count())->toBe(2);
});

it('serializes concurrent verification before querying or changing access', function () {
    $lock = \App\Services\StoreVerificationLock::forUser($this->owner->id);
    expect($lock->get())->toBeTrue();
    $verifier = Mockery::mock(StorePurchaseVerifier::class);
    $verifier->shouldNotReceive('verify'); app()->instance(StorePurchaseVerifier::class, $verifier);
    try { $this->postJson('/api/v1/subscription/verify-receipt', $this->payload)->assertStatus(503); }
    finally { $lock->release(); }
    $this->assertDatabaseCount('store_purchases', 0);
    $this->assertDatabaseCount('store_plan_notifications', 0);
});
