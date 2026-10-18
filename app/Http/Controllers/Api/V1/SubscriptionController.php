<?php
namespace App\Http\Controllers\Api\V1;
use Illuminate\Http\Request;
class SubscriptionController extends ApiController {
    public function prices(Request $request) {
        $data = $request->validate(['country' => ['required', 'regex:/^[A-Z]{2}$/']]);
        $rows = \Illuminate\Support\Facades\DB::table('subscription_packages')
            ->where('audience', 'mobile')->where('country_code', $data['country'])
            ->whereNotNull('tier')->where('is_active', true)->orderBy('sort_order')->get();
        $rows->transform(function ($row) {
            $row->features = json_decode($row->features ?? '[]', true);
            $digits = $row->currency_decimals;
            $row->yearly_price = round((float) $row->price * 12 * (100 - $row->annual_discount) / 100, $digits);
            return $row;
        });
        return $this->success(['country' => $data['country'], 'packages' => $rows,
            'revision' => hash('sha256', $rows->toJson()), 'purchasing_available' => false]);
    }
    public function index(\App\Http\Requests\PaginationRequest $request) {
        $entries = \App\Models\SubscriptionStatus::orderBy('updated_at', 'desc')->paginate($request->input('per_page', 20));
        return $this->success($entries);
    }
    public function store(Request $request) { return $this->success([]); }
    public function show(Request $request) {
        $entry = \App\Models\SubscriptionStatus::where('user_id', $request->user()->id)
            ->latest('expires_at')
            ->first();
        if (!$entry) {
            return $this->success([
                'plan' => null,
                'status' => 'none',
            ]);
        }
        return $this->success($entry);
    }
    public function update(Request $request, $id) { return $this->success([]); }
    public function destroy($id) { return $this->success([]); }
}
