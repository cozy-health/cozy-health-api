<?php
namespace App\Http\Controllers\Api\V1;
use Illuminate\Http\Request;
class SubscriptionController extends ApiController {
    public function prices(Request $request) {
        $data = $request->validate(['country' => ['required', 'regex:/^[A-Z]{2}$/']]);
        $rows = \Illuminate\Support\Facades\DB::table('subscription_packages')
            ->where('audience', 'mobile')->where('country_code', $data['country'])
            ->whereIn('tier', ['standard', 'student', 'premium'])->where('is_active', true)->orderBy('sort_order')->get();
        $rows->transform(function ($row) {
            $row->features = json_decode($row->features ?? '[]', true);
            $digits = $row->currency_decimals;
            $row->yearly_price = round((float) $row->price * 12 * (100 - $row->annual_discount) / 100, $digits);
            return $row;
        });
        return $this->success(['country' => $data['country'], 'packages' => $rows,
            'revision' => hash('sha256', $rows->toJson()), 'purchasing_available' => (bool) config('launch.billing_enabled')]);
    }
    public function index(\App\Http\Requests\PaginationRequest $request) {
        $entries = \App\Models\SubscriptionStatus::orderBy('updated_at', 'desc')->paginate($request->input('per_page', 20));
        return $this->success($entries);
    }
    public function store(Request $request) { return $this->success([]); }
    public function show(Request $request) {
        $testAccess = app(\App\Services\DevelopmentTestAccess::class)->activeFor($request->user()->id);
        if ($testAccess) return $this->success($testAccess)->header('Cache-Control', 'private, no-store');
        $entry = \App\Models\SubscriptionStatus::where('user_id', $request->user()->id)
            ->latest('expires_at')
            ->first();
        if (!$entry) {
            return $this->success([
                'plan' => null,
                'status' => 'none',
            ]);
        }
        if ($entry->expires_at && $entry->expires_at->isPast()) $entry->status = 'expired';
        $purchase = \Illuminate\Support\Facades\DB::table('store_purchases')->where('user_id', $request->user()->id)
            ->where('platform', $entry->platform)->where('product_id', $entry->product_id)
            ->orderByRaw("CASE WHEN status = 'active' AND expires_at > ? THEN 0 ELSE 1 END", [now()])->orderByDesc('expires_at')->first();
        if ($purchase) {
            $entry->setAttribute('store_management', \App\Services\StorePlanPresentation::links($purchase->platform));
            $details = ($purchase->plan_details ?? null) ? json_decode($purchase->plan_details, true, flags: JSON_THROW_ON_ERROR) : null;
            if ($details) {
                $state = $details['state'];
                if (in_array($state, ['active', 'trial', 'canceled', 'grace_period'], true) && $entry->expires_at && !$entry->expires_at->isFuture()) $state = 'expired';
                $entry->setAttribute('store_state', $state);
                $entry->setAttribute('auto_renews', $details['auto_renews']);
                $entry->setAttribute('is_trial', $details['is_trial']);
            }
        }
        return $this->success($entry);
    }
    public function update(Request $request, $id) { return $this->success([]); }
    public function destroy($id) { return $this->success([]); }
}
