<?php

namespace App\Http\Controllers\Api\V1\Provider;

use App\Http\Controllers\Controller;
use App\Models\ProviderAccessLog;
use App\Services\Provider\ProviderClientAccess;
use Illuminate\Http\Request;

class ProviderAccessLogController extends Controller
{
    public function provider(Request $request)
    {
        $query = ProviderAccessLog::where('provider_id', $request->user()->id);
        $request->validate(['action' => ['sometimes', 'string', 'max:100'], 'resource_type' => ['sometimes', 'string', 'max:100']]);
        $perPage = ProviderClientAccess::pagination($request);
        foreach (['action', 'resource_type'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->input($filter));
            }
        }
        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->input('from'));
        }
        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->input('to'));
        }

        return response()->json($query->orderByDesc('created_at')->orderByDesc('id')->paginate($perPage));
    }

    public function patient(Request $request)
    {
        $entries = ProviderAccessLog::where('user_id', $request->user()->id)->with(['provider', 'link.provider'])
            ->orderByDesc('created_at')->orderByDesc('id')->paginate(ProviderClientAccess::pagination($request));

        return response()->json($entries->through(function ($entry) {
            $provider = $entry->provider ?? $entry->link?->provider;

            return ['id' => $entry->id, 'provider' => ['name' => $provider?->name ?? $entry->link?->provider_name ?? 'Invited provider',
                'practice' => $provider?->practice], 'action' => $entry->action,
                'resource_type' => $entry->resource_type, 'created_at' => $entry->created_at];
        }));
    }
}
