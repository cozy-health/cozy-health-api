<?php

namespace App\Http\Controllers;

use App\Services\Analytics\AdminMetrics;
use App\Services\Analytics\PublishedComparison;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class AdminMetricsController extends Controller
{
    public function show(Request $request, string $metric, AdminMetrics $metrics)
    {
        abort_unless(in_array($metric, ['overview', 'usage', 'retention', 'safety'], true), 404);
        $allowed = $metric === 'usage' ? ['range', 'view'] : ['view'];
        abort_if(array_diff(array_keys($request->query()), $allowed), 422, 'Only fixed reporting ranges are supported.');
        $data = $request->validate(['range' => ['sometimes', 'in:7d,30d,90d'], 'view' => ['sometimes', 'in:internal,published']]);
        $internal = ($data['view'] ?? 'published') === 'internal';
        $snapshot = $internal ? $metrics->internalSnapshot() : $metrics->snapshot();
        $meta = array_intersect_key($snapshot, array_flip(['release', 'timezone', 'minimum_cohort', 'precision', 'window', 'activity_definition', 'view', 'as_of']));
        $payload = $metric === 'usage' ? ['range' => $data['range'] ?? '30d', 'features' => $snapshot['usage'][$data['range'] ?? '30d'], 'unavailable_features' => $snapshot['unavailable_features']] : $snapshot[$metric];
        if ($metric === 'overview') {
            $payload['operations'] = $this->health();
        }

        if ($internal && $metric === 'usage') {
            $end = \Carbon\CarbonImmutable::parse($snapshot['as_of']);
            $meta['window'] = ['from' => $end->startOfDay()->subDays(AdminMetrics::RANGES[$data['range'] ?? '30d'] - 1)->toDateString(), 'to_exclusive' => $snapshot['as_of']];
        }
        $comparison = $metric !== 'usage' ? null : ($internal ? ['status' => 'unavailable', 'previous_release' => null,
            'current_window' => $meta['window'], 'previous_window' => null, 'metrics' => (object) []]
            : app(PublishedComparison::class)->make('metrics', $snapshot, $data['range'] ?? '30d'));
        return response()->json(['meta' => $meta, 'data' => $payload, ...($metric === 'usage' ? ['comparison' => $comparison] : [])])->header('Cache-Control', 'private, no-store');
    }

    private function health(): array
    {
        $db = 'down';
        try {
            DB::select('SELECT 1');
            $db = 'ok';
        } catch (Throwable) {
        }

        return ['api' => 'ok', 'database' => $db, 'checked_at' => now('UTC')->toIso8601String(),
            'source' => 'Current API request and SELECT 1; no historical monitoring window.',
            'availability_history' => ['status' => 'unavailable'], 'latency_p95' => ['status' => 'unavailable'],
            'latency_p99' => ['status' => 'unavailable'], 'error_rate' => ['status' => 'unavailable'],
            'queue_health' => ['status' => 'unavailable'], 'deployment' => ['status' => 'unavailable']];
    }
}
