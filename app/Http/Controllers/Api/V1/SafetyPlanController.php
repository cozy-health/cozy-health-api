<?php
namespace App\Http\Controllers\Api\V1;
use Illuminate\Http\Request;
class SafetyPlanController extends ApiController {
    public function index(\App\Http\Requests\PaginationRequest $request) {
        $entries = \App\Models\SafetyPlan::orderBy('updated_at', 'desc')->paginate($request->input('per_page', 20));
        return $this->success($entries);
    }
    public function store(\App\Http\Requests\SafetyPlanRequest $request) {
        return \Illuminate\Support\Facades\DB::transaction(function () use ($request) {
            $data = $request->validated();
            $data['id'] ??= (string) \Illuminate\Support\Str::uuid();
            $data['user_id'] = $request->user()->id;
            $error = app(\App\Services\SyncRecordWriter::class)->upsert(\App\Models\SafetyPlan::class, 'safety_plan', $request->user(), $data);
            if ($error !== null) return $this->error('This record cannot be saved with that identifier.', 'CONFLICT', [], 409);
            return $this->success(\App\Models\SafetyPlan::withTrashed()->where('user_id', $request->user()->id)->firstOrFail());
        });
    }
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
}
