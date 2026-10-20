<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\MoodEntry;
use Illuminate\Http\Request;

class MoodStatsController extends ApiController
{
    public function __invoke(Request $request)
    {
        $data = $request->validate(['days' => ['sometimes', 'integer', 'min:1', 'max:365']]);
        $days = (int) ($data['days'] ?? 30);
        $end = $request->user()->localToday()->addDay();
        $start = $end->copy()->subDays($days);
        $rows = MoodEntry::where('user_id', $request->user()->id)->where('is_deleted', false)
            ->whereRaw('COALESCE(client_created_at, created_at) >= ?', [$start->copy()->utc()])
            ->whereRaw('COALESCE(client_created_at, created_at) < ?', [$end->copy()->utc()])
            ->selectRaw('mood, COUNT(*) AS entry_count, SUM(intensity) AS intensity_sum')
            ->groupBy('mood')->orderBy('mood')->get();
        $total = (int) $rows->sum('entry_count');

        return $this->success([
            'days' => $days, 'timezone' => $request->user()->timezone,
            'start_date' => $start->toDateString(), 'end_date' => $end->copy()->subDay()->toDateString(),
            'entry_count' => $total,
            // Intensity is reported as intensity, never a happiness/health score.
            'average_intensity' => $total ? round($rows->sum('intensity_sum') / $total, 2) : null,
            'moods' => $rows->map(fn ($row) => ['mood' => $row->mood, 'entry_count' => (int) $row->entry_count,
                'average_intensity' => round($row->intensity_sum / $row->entry_count, 2)]),
        ]);
    }
}
