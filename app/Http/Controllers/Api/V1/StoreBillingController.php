<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller;
use App\Models\StudentApplication;
use App\Models\SubscriptionStatus;
use App\Services\StorePurchaseVerifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
class StoreBillingController extends Controller
{
    public function configuration(Request $request)
    {
        DB::table('store_accounts')->insertOrIgnore(['user_id' => $request->user()->id, 'account_token' => (string) Str::uuid()]);
        return response()->json(['enabled' => (bool) config('launch.billing_enabled'), 'account_token' => DB::table('store_accounts')->where('user_id', $request->user()->id)->value('account_token'), 'products' => config('launch.products'), 'student_eligible' => StudentApplication::where('user_id', $request->user()->id)->where('status', 'approved')->where('eligible_until', '>', now())->exists()]);
    }
    public function verify(Request $request, StorePurchaseVerifier $verifier)
    {
        abort_unless(config('launch.billing_enabled'), 503, 'Store purchasing is not activated.');
        $data = $request->validate(['platform' => ['required', 'in:apple,google'], 'reference' => ['required', 'string', 'max:5000']]);
        try {
            return \App\Services\StoreVerificationLock::forUser($request->user()->id)
                ->block(5, fn () => $this->verifyLocked($request, $verifier, $data));
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException) {
            abort(503, 'Subscription verification is busy. Retry without purchasing again.');
        }
    }
    private function verifyLocked(Request $request, StorePurchaseVerifier $verifier, array $data)
    {
        try { $purchase = $verifier->verify($data['platform'], $data['reference']); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) { throw $e; }
        catch (\Throwable) { abort(503, 'Store verification is temporarily unavailable. Retry without purchasing again.'); }
        $product = config('launch.products')[$purchase['product_id']] ?? null;
        abort_unless(is_array($product) && ($product['platform'] ?? '') === $data['platform'] && in_array($product['tier'] ?? '', ['standard', 'premium', 'student'], true), 422, 'Unrecognized store product.');
        $account = DB::table('store_accounts')->where('user_id', $request->user()->id)->first();
        abort_unless($account && hash_equals($account->account_token, strtolower($purchase['account_token'])), 403, 'Purchase belongs to another account.');
        $identity = hash('sha256', $data['platform'].':'.$purchase['identity']);
        $planDetails = isset($purchase['plan_details']) ? [...$purchase['plan_details'], 'tier' => $product['tier']] : null;
        $purchaseId = null;
        $planVersion = 0;
        $result = DB::transaction(function () use ($request, $data, $purchase, $product, $identity, $planDetails, &$purchaseId, &$planVersion) {
            $request->user()->newQuery()->whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $existing = DB::table('store_purchases')->where('purchase_hash', $identity)->lockForUpdate()->first();
            abort_if($existing && $existing->user_id !== $request->user()->id, 403, 'Purchase is already associated with another account.');
            if (!$existing && $product['tier'] === 'student') abort_unless(StudentApplication::where('user_id', $request->user()->id)->where('status', 'approved')->where('eligible_until', '>', now())->exists(), 403, 'Current student approval is required.');
            $id = $existing->id ?? (string) Str::uuid();
            $purchaseId = $id;
            $previousDetails = ($existing->plan_details ?? null) ? json_decode($existing->plan_details, true, flags: JSON_THROW_ON_ERROR) : null;
            $planVersion = (int) ($existing->plan_version ?? 0);
            if ($planDetails && (!$previousDetails || \App\Services\StorePlanNotifications::fingerprint($previousDetails) !== \App\Services\StorePlanNotifications::fingerprint($planDetails))) $planVersion++;
            DB::table('store_purchases')->updateOrInsert(['purchase_hash' => $identity], ['id' => $id, 'user_id' => $request->user()->id, 'platform' => $data['platform'], 'purchase_reference' => Crypt::encryptString($purchase['reference']), 'product_id' => $purchase['product_id'], 'tier' => $product['tier'], 'status' => $purchase['status'], 'expires_at' => $purchase['expires_at'], 'verified_at' => now(), 'created_at' => $existing->created_at ?? now(), 'updated_at' => now(), 'plan_details' => $planDetails ? json_encode($planDetails, JSON_THROW_ON_ERROR) : null]);
            DB::table('store_purchases')->where('id', $id)->update(['plan_version' => $planVersion]);
            if ($planDetails) app(\App\Services\StorePlanNotifications::class)->record($request->user(), $id, $existing, $planDetails, $planVersion);
            $best = DB::table('store_purchases')->where('user_id', $request->user()->id)->orderByRaw("CASE WHEN status = 'active' AND expires_at > ? THEN 0 ELSE 1 END", [now()])->orderByDesc('expires_at')->first();
            $status = SubscriptionStatus::withoutGlobalScopes()->firstOrNew(['user_id' => $request->user()->id]);
            if (!$status->exists) $status->id = (string) Str::uuid();
            $purchase['status'] = $best->status; $purchase['expires_at'] = \Illuminate\Support\Carbon::parse($best->expires_at); $purchase['product_id'] = $best->product_id;
            $product['tier'] = $best->tier;
            $changed = !$status->exists || $status->status !== $purchase['status'] || !$status->expires_at?->equalTo($purchase['expires_at']) || $status->product_id !== $purchase['product_id'];
            if ($changed) $status->fill(['user_id' => $request->user()->id, 'plan' => $product['tier'], 'status' => $purchase['status'], 'platform' => $best->platform, 'product_id' => $purchase['product_id'], 'transaction_id' => hash('sha256', $data['reference']), 'original_transaction_id' => $identity, 'started_at' => $status->started_at ?? now(), 'expires_at' => $purchase['expires_at'], 'last_checked' => now()])->save();
            return $status;
        });
        try { $verifier->acknowledge($purchase); } catch (\Throwable) { abort(503, 'Verification saved; retry to finish store acknowledgement.'); }
        if ($planDetails && $purchaseId) {
            try { app(\App\Services\StorePlanNotifications::class)->releaseAfterAcknowledgement($purchaseId, $planVersion); }
            catch (\Throwable) { \Log::warning('Store plan email dispatch needs retry.'); }
        }
        return response()->json(['data' => $result]);
    }
}
