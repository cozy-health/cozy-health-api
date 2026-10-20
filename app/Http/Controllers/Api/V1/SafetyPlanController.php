<?php
namespace App\Http\Controllers\Api\V1;
use Illuminate\Http\Request;
class SafetyPlanController extends ApiController {
    public function index(\App\Http\Requests\PaginationRequest $request) {
        $entries = \App\Models\SafetyPlan::orderBy('updated_at', 'desc')->paginate($request->input('per_page', 20));
        return $this->success($entries);
    }
    public function store(Request $request) { return $this->success([]); }
    public function show(Request $request) {
        $entry = \App\Models\SafetyPlan::where('user_id', $request->user()->id)
            ->where('is_deleted', false)
            ->first();
        if (!$entry) {
            return response()->json([
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => 'Resource not found.'
                ]
            ], 404);
        }
        return $this->success($entry);
    }
    public function update(Request $request, $id) { return $this->success([]); }
    public function destroy($id) { return $this->success([]); }
}
