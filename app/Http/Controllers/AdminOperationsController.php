<?php

namespace App\Http\Controllers;

use App\Services\{AdminAudit, AdminContentVersion};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminOperationsController extends Controller
{
    private function supportVersion(object $row): string
    {
        return hash('sha256', json_encode([$row->id, $row->status, $row->admin_notes, $row->updated_at, $row->resolved_at], JSON_THROW_ON_ERROR));
    }

    public function reportDetail(Request $request, string $id)
    {
        $report = DB::table('community_reports')->where('id', $id)->firstOrFail(['id', 'target_type', 'target_id', 'reason', 'status', 'due_at', 'created_at', 'updated_at', 'resolved_at']);
        $table = match ($report->target_type) {
            'post' => 'community_posts', 'comment' => 'community_comments', default => null
        };
        $target = $table ? DB::table($table)->where('id', $report->target_id)->whereNull('deleted_at')->first(['id', 'body', 'status', 'is_anonymous', 'created_at', 'updated_at', ...($table === 'community_posts' ? ['image_object_key'] : [])]) : null;
        if ($target) { $target->version = AdminContentVersion::token($target); $target->image_url = app(\App\Services\CommunityImageService::class)->url($target->image_object_key ?? null); unset($target->image_object_key); }
        unset($report->target_id);
        AdminAudit::record($request->user()->id, 'review-content', 'community-reports', $id);

        return response()->json(['data' => [...(array) $report, 'target' => $target]])->header('Cache-Control', 'private, no-store');
    }

    public function supportDetail(Request $request, string $id)
    {
        $row = DB::table('support_reports')->where('id', $id)->firstOrFail(['id', 'subject', 'message', 'status', 'admin_notes', 'created_at', 'updated_at', 'resolved_at']);
        AdminAudit::record($request->user()->id, 'review-content', 'support-reports', $id);

        return response()->json(['data' => [...(array) $row, 'version' => $this->supportVersion($row)]])->header('Cache-Control', 'private, no-store');
    }

    public function reports(Request $request)
    {
        $data = $request->validate(['page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|in:10,20,50', 'search' => 'sometimes|string|max:100', 'status' => 'sometimes|in:pending,resolved', 'overdue' => 'sometimes|boolean']);
        // Do not return snapshots: anonymous reports contain internal author IDs.
        $query = DB::table('community_reports')->select('id', 'target_type', 'target_id', 'reason', 'status', 'due_at', 'created_at', 'updated_at', 'resolved_at');
        if (isset($data['status'])) {
            $query->where('status', $data['status']);
        }
        if (! empty($data['overdue'])) {
            $query->where('status', 'pending')->where('due_at', '<', now('UTC'));
        }
        if (! empty($data['search'])) {
            $query->where('reason', 'like', '%'.$data['search'].'%');
        }

        return response()->json(['data' => $query->orderByDesc('id')->paginate($data['per_page'] ?? 10)])->header('Cache-Control', 'private, no-store');
    }

    public function review(Request $request, string $id)
    {
        $data = $request->validate(['decision' => 'required|in:publish,remove,dismiss', 'expected_version' => 'required_unless:decision,dismiss|string|size:64', 'confirm_republish' => 'sometimes|boolean']);
        DB::transaction(function () use ($request, $id, $data) {
            // Discover the immutable target, then always lock content before reports.
            $report = DB::table('community_reports')->where('id', $id)->firstOrFail();
            $table = match ($report->target_type) {
                'post' => 'community_posts', 'comment' => 'community_comments', default => null
            };
            abort_unless($table, 422);
            $target = DB::table($table)->where('id', $report->target_id)->whereNull('deleted_at')->lockForUpdate()->first();
            $report = DB::table('community_reports')->where('id', $id)->lockForUpdate()->firstOrFail();
            abort_unless($report->status === 'pending', 409, 'This report was already reviewed.');
            $affected = [$id];
            if ($data['decision'] !== 'dismiss') {
                abort_unless($target, 409, 'The reported content is no longer available.');
                $status = $data['decision'] === 'publish' ? 'published' : 'removed';
                AdminContentVersion::check($target, $data, $status);
                DB::table($table)->where('id', $report->target_id)->update(['status' => $status, 'updated_at' => now()]);
                \App\Services\AdminReportResolution::resolvePending($request->user()->id, $report->target_type, $report->target_id, $data['decision']);
                return;
            }
            DB::table('community_reports')->whereIn('id', $affected)->update(['status' => 'resolved', 'resolved_at' => now('UTC'), 'updated_at' => now()]);
            foreach ($affected as $affectedId) AdminAudit::record($request->user()->id, $data['decision'], 'community-reports', (string) $affectedId, ['status', 'resolved_at']);
        });

        return response()->json(['message' => 'Review saved.']);
    }

    public function target(Request $request, string $id)
    {
        $report = DB::table('community_reports')->where('id', $id)->firstOrFail();
        $table = match ($report->target_type) {
            'post' => 'community_posts', 'comment' => 'community_comments', default => null
        };
        abort_unless($table, 404);
        $row = DB::table($table)->where('id', $report->target_id)->whereNull('deleted_at')->select('id', 'body', 'status', 'is_anonymous', 'created_at')->first();
        AdminAudit::record($request->user()->id, 'review-content', 'community-reports', $id);

        return response()->json(['data' => $row])->header('Cache-Control', 'private, no-store');
    }

    public function subscriptions(Request $request)
    {
        $data = $request->validate(['search' => 'sometimes|string|max:100', 'page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|in:10,20,50']);
        $query = DB::table('subscription_statuses')->select('id', 'user_id', 'plan', 'status', 'platform', 'started_at', 'expires_at', 'cancelled_at');
        if (! empty($data['search'])) {
            $query->where(fn ($q) => $q->where('plan', 'like', '%'.$data['search'].'%')->orWhere('status', 'like', '%'.$data['search'].'%'));
        }

        return response()->json(['data' => $query->orderByDesc('started_at')->paginate($data['per_page'] ?? 10)])->header('Cache-Control', 'private, no-store');
    }

    public function subscriptionHistory(Request $request)
    {
        $data = $request->validate(['search' => 'sometimes|string|max:100', 'page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|in:10,20,50']);
        $query = DB::table('subscription_events');
        if (! empty($data['search'])) {
            $query->where(fn ($q) => $q->where('plan', 'like', '%'.$data['search'].'%')->orWhere('status', 'like', '%'.$data['search'].'%'));
        }

        return response()->json(['data' => $query->orderByDesc('id')->paginate($data['per_page'] ?? 10)])->header('Cache-Control', 'private, no-store');
    }

    public function support(Request $request)
    {
        $data = $request->validate(['page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|in:10,20,50', 'search' => 'sometimes|string|max:100', 'status' => 'sometimes|in:open,resolved']);
        $query = DB::table('support_reports')->select('id', 'subject', 'message', 'status', 'admin_notes', 'created_at', 'updated_at', 'resolved_at');
        if (isset($data['status'])) {
            $query->where('status', $data['status']);
        }
        if (! empty($data['search'])) {
            $query->where('subject', 'like', '%'.$data['search'].'%');
        }

        return response()->json(['data' => $query->orderByDesc('id')->paginate($data['per_page'] ?? 10)])->header('Cache-Control', 'private, no-store');
    }

    public function resolveSupport(Request $request, string $id)
    {
        $data = $request->validate(['status' => 'required|in:open,resolved', 'admin_notes' => 'nullable|string|max:2000', 'expected_updated_at' => 'sometimes|date', 'expected_status' => 'sometimes|in:open,resolved', 'expected_version' => 'sometimes|string|size:64']);
        DB::transaction(function () use ($request, $id, $data) {
            $old = DB::table('support_reports')->where('id', $id)->lockForUpdate()->firstOrFail();
            abort_if(isset($data['expected_version']) && ! hash_equals($this->supportVersion($old), $data['expected_version']), 409);
            abort_if(isset($data['expected_updated_at']) && $data['expected_updated_at'] !== $old->updated_at || isset($data['expected_status']) && $data['expected_status'] !== $old->status, 409);
            unset($data['expected_updated_at'], $data['expected_status'], $data['expected_version']);
            if ($old->status !== $data['status']) {
                $data['resolved_at'] = $data['status'] === 'resolved' ? now('UTC') : null;
            }
            DB::table('support_reports')->where('id', $id)->update([...$data, 'updated_at' => now()]);
            AdminAudit::record($request->user()->id, 'review', 'support-reports', $id, array_keys($data));
        });

        return response()->json(['message' => 'Report updated.']);
    }
}
