<?php

namespace App\Http\Controllers;

use App\Services\Analytics\AdminInsights;
use App\Services\Analytics\PublishedComparison;
use Illuminate\Http\Request;

class AdminInsightsController extends Controller
{
    public function __invoke(Request $request, AdminInsights $insights)
    {
        abort_if(array_diff(array_keys($request->query()), ['range', 'view']), 422, 'Only fixed reporting ranges are supported.');
        $data = $request->validate(['range' => ['sometimes', 'in:7d,30d,90d'], 'view' => ['sometimes', 'in:internal,published']]);
        $internal = ($data['view'] ?? 'published') === 'internal';
        $snapshot = $internal ? $insights->internalSnapshot() : $insights->snapshot();
        $range = $data['range'] ?? '30d';
        if ($internal) {
            $end = \Carbon\CarbonImmutable::parse($snapshot['meta']['as_of']);
            $snapshot['meta']['window'] = ['from' => $end->startOfDay()->subDays(\App\Services\Analytics\AdminMetrics::RANGES[$range] - 1)->toDateString(), 'to_exclusive' => $snapshot['meta']['as_of']];
        }
        $comparison = $internal ? ['status' => 'unavailable', 'previous_release' => null,
            'current_window' => $snapshot['meta']['window'], 'previous_window' => null, 'metrics' => (object) []]
            : app(PublishedComparison::class)->make('insights', $snapshot, $range);

        return response()->json(['meta' => $snapshot['meta'], 'data' => $snapshot['ranges'][$data['range'] ?? '30d'], 'comparison' => $comparison])->header('Cache-Control', 'private, no-store');
    }
}
