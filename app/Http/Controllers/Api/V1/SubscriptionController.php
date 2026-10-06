<?php
namespace App\Http\Controllers\Api\V1;
use Illuminate\Http\Request;
class SubscriptionController extends ApiController {
    public function index(Request $request) { 
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
