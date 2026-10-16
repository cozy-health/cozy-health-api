<?php

namespace App\Services\Analytics;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Compares only values already released; never queries historic raw records. */
class PublishedComparison
{
    public function make(string $family, array $snapshot, string $range): array
    {
        $release = $family === 'insights' ? $snapshot['meta']['release'] : $snapshot['release'];
        $end = CarbonImmutable::parse($release, 'UTC');
        $previousEnd = $end->subMonthNoOverflow();
        $days = AdminMetrics::RANGES[$range];
        $stored = DB::table('admin_metric_releases')->where('release_key', 'admin-'.$family.':v1:'.$previousEnd->format('Y-m'))->value('payload');
        $previous = $stored ? json_decode($stored, true, flags: JSON_THROW_ON_ERROR) : null;
        $currentValues = $family === 'insights' ? $snapshot['ranges'][$range]['summary'] : collect($snapshot['usage'][$range])->keyBy('feature')->all();
        $previousValues = $previous ? ($family === 'insights' ? ($previous['ranges'][$range]['summary'] ?? []) : collect($previous['usage'][$range] ?? [])->keyBy('feature')->all()) : [];
        $metrics = [];
        foreach ($currentValues as $key => $cell) {
            $prior = $previousValues[$key] ?? null;
            $safe = ($cell['status'] ?? null) === 'available' && ($prior['status'] ?? null) === 'available' && is_numeric($cell['count']) && is_numeric($prior['count']);
            $metrics[$key] = $safe ? ['status' => 'available', 'previous_count' => $prior['count'], 'change_percent' => $prior['count'] > 0 ? round(($cell['count'] - $prior['count']) / $prior['count'] * 100, 1) : null] : ['status' => 'unavailable', 'previous_count' => null, 'change_percent' => null];
        }

        return ['status' => $previous ? 'available' : 'unavailable', 'previous_release' => $previous ? $previousEnd->toDateString() : null,
            'current_window' => ['from' => $end->subDays($days)->toDateString(), 'to_exclusive' => $end->toDateString()],
            'previous_window' => $previous ? ['from' => $previousEnd->subDays($days)->toDateString(), 'to_exclusive' => $previousEnd->toDateString()] : null, 'metrics' => $metrics];
    }
}
