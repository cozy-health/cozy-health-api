<?php

namespace App\Http\Controllers;

use App\Services\Analytics\AdminInsights;
use App\Services\Analytics\PublishedComparison;
use Illuminate\Http\Request;

class AdminInsightsController extends Controller
{
    public function __invoke(Request $request, AdminInsights $insights)
    {
        abort_if(array_diff(array_keys($request->query()), ['range']), 422, 'Only fixed reporting ranges are supported.');
        $data = $request->validate(['range' => ['sometimes', 'in:7d,30d,90d']]);
        $snapshot = $insights->snapshot();

        return response()->json(['meta' => $snapshot['meta'], 'data' => $snapshot['ranges'][$data['range'] ?? '30d'], 'comparison' => app(PublishedComparison::class)->make('insights', $snapshot, $data['range'] ?? '30d')])->header('Cache-Control', 'private, no-store');
    }
}
