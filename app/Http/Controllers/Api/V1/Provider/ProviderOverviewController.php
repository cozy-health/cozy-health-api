<?php

namespace App\Http\Controllers\Api\V1\Provider;

use App\Http\Controllers\Controller;
use App\Models\{ProviderAccessLog, ProviderMessage};
use App\Services\Provider\ProviderOverviewData;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProviderOverviewController extends Controller
{
    public function __invoke(Request $request, ProviderOverviewData $visible)
    {
        abort_if(count($request->query()), 422, 'Unsupported filter.');
        abort_unless($request->user()->is_verified, 403, 'Provider verification is required.');
        return DB::transaction(function () use ($request, $visible) {
            $links = $visible->links($request->user());
            $totals = [
                'connected_patients' => $links->pluck('user_id')->unique()->count(),
                'unread_messages' => ProviderMessage::whereIn('provider_client_link_id', $links->pluck('id'))
                    ->where('sender', 'patient')->whereNull('read_at')->count(),
                'shared_assessments' => $visible->shares($request->user(), $links)->count(),
            ];
            ProviderAccessLog::create(['provider_id' => $request->user()->id, 'action' => 'view', 'resource_type' => 'overview',
                'ip_address' => $request->ip(), 'user_agent' => $request->userAgent()]);
            return response()->json(['data' => ['checked_at' => now('UTC')->toIso8601String(), 'totals' => $totals]])
                ->header('Cache-Control', 'private, no-store');
        });
    }
}
