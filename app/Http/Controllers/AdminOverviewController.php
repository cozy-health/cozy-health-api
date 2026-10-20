<?php

namespace App\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminOverviewController extends Controller
{
    public function __invoke(Request $request)
    {
        abort_if(count($request->query()), 422);
        $token = $request->user()->currentAccessToken();
        $totals = [];
        $attention = [];
        $resources = [];
        $queue = null;
        if ($token->can('admin:users')) {
            $totals['consumer_accounts'] = DB::table('users')->where('role', 'user')->where('is_demo', false)->whereNull('deleted_at')->count();
            $resources[] = 'users';
        }
        if ($token->can('admin:moderation')) {
            $windowStart = now('UTC')->subDays(30);
            $resolution = [];
            foreach (['reports' => 'community_reports', 'support' => 'support_reports'] as $kind => $table) {
                $durations = DB::table($table)->where('status', 'resolved')->where('resolved_at', '>=', $windowStart)->where('resolved_at', '<=', now('UTC'))->get(['created_at', 'resolved_at'])
                    ->map(fn ($row) => max(0, CarbonImmutable::parse($row->resolved_at, 'UTC')->timestamp - CarbonImmutable::parse($row->created_at, 'UTC')->timestamp))->sort()->values();
                $n = $durations->count();
                $resolution[$kind] = ['median_seconds' => $n ? ($durations[(int) floor(($n - 1) / 2)] + $durations[(int) floor($n / 2)]) / 2 : null, 'sample_count' => $n];
            }
            $oldest = DB::table('support_reports')->where('status', 'open')->min('created_at');
            $queue = ['overdue_reports' => DB::table('community_reports')->where('status', 'pending')->where('due_at', '<', now('UTC'))->count(),
                'oldest_open_support_seconds' => $oldest ? max(0, now('UTC')->timestamp - CarbonImmutable::parse($oldest, 'UTC')->timestamp) : null,
                'resolution' => [...$resolution, 'window' => ['from' => $windowStart->toIso8601String(), 'to' => now('UTC')->toIso8601String()]]];
            $totals['pending_reports'] = DB::table('community_reports')->where('status', 'pending')->count();
            $totals['open_support'] = DB::table('support_reports')->where('status', 'open')->count();
            // Return operational metadata only, never report bodies or author identity.
            foreach (DB::table('community_reports')->where('status', 'pending')->orderByRaw('CASE WHEN due_at IS NULL THEN 1 ELSE 0 END')->orderBy('due_at')->orderBy('created_at')->limit(5)->get(['id', 'created_at', 'due_at']) as $row) {
                $attention[] = ['kind' => 'reports', 'label' => 'Community report', 'id' => $row->id, 'created_at' => $row->created_at, 'due_at' => $row->due_at];
            }
            foreach (DB::table('support_reports')->where('status', 'open')->orderBy('created_at')->limit(5)->get(['id', 'created_at']) as $row) {
                $attention[] = ['kind' => 'support', 'label' => 'Support request', 'id' => $row->id, 'created_at' => $row->created_at, 'due_at' => null];
            }
            usort($attention, fn ($a, $b) => strcmp($a['due_at'] ?? '9999', $b['due_at'] ?? '9999') ?: strcmp($a['created_at'], $b['created_at']));
            $resources = [...$resources, 'community-reports', 'support-reports', 'community-posts', 'community-comments', 'community-members'];
        }
        if ($token->can('admin:subscriptions')) {
            $totals['active_subscriptions'] = DB::table('subscription_statuses')->where('status', 'active')->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->count();
            $resources[] = 'packages';
        }
        if ($token->can('admin:content')) {
            $resources = [...$resources, 'moods', 'expressions', 'triggers', 'coping', 'communities', 'challenges', 'quizzes'];
        }
        if ($token->can('admin:email')) {
            $resources[] = 'email';
        }
        $activity = DB::table('admin_audit_logs')->whereIn('resource', $resources)->whereNotIn('action', ['review-list', 'review-content'])->orderByDesc('id')->limit(8)->get(['id', 'action', 'resource', 'created_at']);

        return response()->json(['data' => ['checked_at' => now('UTC')->toIso8601String(), 'totals' => $totals, 'attention' => array_slice($attention, 0, 6), 'activity' => $activity, ...($queue !== null ? ['queue' => $queue] : [])]])->header('Cache-Control', 'private, no-store');
    }
}
