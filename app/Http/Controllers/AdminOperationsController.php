<?php
namespace App\Http\Controllers;
use App\Services\AdminAudit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
class AdminOperationsController extends Controller
{
    public function reports(Request $request)
    {
        $data = $request->validate(['page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|in:10,20,50', 'search' => 'sometimes|string|max:100']);
        // Do not return snapshots: anonymous reports contain internal author IDs.
        $query = DB::table('community_reports')->select('id', 'target_type', 'target_id', 'reason', 'status', 'due_at', 'created_at');
        if (!empty($data['search'])) $query->where('reason', 'like', '%'.$data['search'].'%');
        return response()->json(['data' => $query->orderByDesc('id')->paginate($data['per_page'] ?? 10)])->header('Cache-Control', 'private, no-store');
    }
    public function review(Request $request, string $id)
    {
        $data = $request->validate(['decision' => 'required|in:publish,remove,dismiss']);
        DB::transaction(function () use ($request, $id, $data) {
            $report = DB::table('community_reports')->where('id', $id)->lockForUpdate()->firstOrFail();
            abort_unless($report->status === 'pending', 409, 'This report was already reviewed.');
            $table = match ($report->target_type) { 'post' => 'community_posts', 'comment' => 'community_comments', default => null };
            abort_unless($table, 422);
            if ($data['decision'] !== 'dismiss') DB::table($table)->where('id', $report->target_id)->whereNull('deleted_at')->update(['status' => $data['decision'] === 'publish' ? 'published' : 'removed', 'updated_at' => now()]);
            DB::table('community_reports')->where('id', $id)->update(['status' => 'resolved', 'updated_at' => now()]);
            AdminAudit::record($request->user()->id, $data['decision'], 'community-reports', $id);
        });
        return response()->json(['message' => 'Review saved.']);
    }
    public function target(Request $request, string $id)
    {
        $report = DB::table('community_reports')->where('id', $id)->firstOrFail();
        $table = match ($report->target_type) { 'post' => 'community_posts', 'comment' => 'community_comments', default => null };
        abort_unless($table, 404);
        $row = DB::table($table)->where('id', $report->target_id)->select('id', 'body', 'status', 'is_anonymous', 'created_at')->first();
        AdminAudit::record($request->user()->id, 'review-content', 'community-reports', $id);
        return response()->json(['data' => $row])->header('Cache-Control', 'private, no-store');
    }
    public function subscriptions(Request $request)
    {
        $data = $request->validate(['search' => 'sometimes|string|max:100', 'page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|in:10,20,50']);
        $query = DB::table('subscription_statuses')->select('id', 'user_id', 'plan', 'status', 'platform', 'started_at', 'expires_at', 'cancelled_at');
        if (!empty($data['search'])) $query->where(fn ($q) => $q->where('plan', 'like', '%'.$data['search'].'%')->orWhere('status', 'like', '%'.$data['search'].'%'));
        return response()->json(['data' => $query->orderByDesc('started_at')->paginate($data['per_page'] ?? 10)])->header('Cache-Control', 'private, no-store');
    }
    public function subscriptionHistory(Request $request)
    {
        $data = $request->validate(['search' => 'sometimes|string|max:100', 'page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|in:10,20,50']);
        $query = DB::table('subscription_events');
        if (!empty($data['search'])) $query->where(fn ($q) => $q->where('plan', 'like', '%'.$data['search'].'%')->orWhere('status', 'like', '%'.$data['search'].'%'));
        return response()->json(['data' => $query->orderByDesc('id')->paginate($data['per_page'] ?? 10)])->header('Cache-Control', 'private, no-store');
    }
    public function support(Request $request)
    {
        $data = $request->validate(['page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|in:10,20,50', 'search' => 'sometimes|string|max:100']);
        $query = DB::table('support_reports')->select('id', 'subject', 'message', 'status', 'created_at');
        if (!empty($data['search'])) $query->where('subject', 'like', '%'.$data['search'].'%');
        return response()->json(['data' => $query->orderByDesc('id')->paginate($data['per_page'] ?? 10)])->header('Cache-Control', 'private, no-store');
    }
    public function resolveSupport(Request $request, string $id)
    {
        $data = $request->validate(['status' => 'required|in:open,resolved', 'admin_notes' => 'nullable|string|max:2000']);
        DB::transaction(function () use ($request, $id, $data) {
            DB::table('support_reports')->where('id', $id)->lockForUpdate()->firstOrFail();
            DB::table('support_reports')->where('id', $id)->update([...$data, 'updated_at' => now()]);
            AdminAudit::record($request->user()->id, 'review', 'support-reports', $id, array_keys($data));
        });
        return response()->json(['message' => 'Report updated.']);
    }
}
