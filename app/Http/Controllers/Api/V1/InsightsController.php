<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MoodEntry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InsightsController extends Controller
{
    private const ENTRY_TIME = 'COALESCE(client_created_at, created_at)';

    public function weekly(Request $request): JsonResponse
    {
        $validated = $request->validate(['weeks' => ['sometimes', 'integer', 'in:1,4,12']]);
        $weeks = (int) ($validated['weeks'] ?? 1);
        $today = CarbonImmutable::instance($request->user()->localToday());
        $end = $today->addDay();
        $start = $today->startOfWeek(CarbonImmutable::MONDAY)->subWeeks($weeks - 1);
        $case = 'CASE';
        $bindings = [];
        for ($week = $start; $week->lessThan($end); $week = $week->addWeek()) {
            $case .= ' WHEN '.self::ENTRY_TIME.' >= ? AND '.self::ENTRY_TIME.' < ? THEN ?';
            array_push($bindings, $week->utc()->toDateTimeString(), $week->addWeek()->utc()->toDateTimeString(), $week->toDateString());
        }
        $rows = $this->between($request, $start, $end)
            ->selectRaw($case.' END AS week_start', $bindings)
            ->selectRaw('mood, COUNT(*) AS entry_count, SUM(intensity) AS total_intensity')
            ->groupBy('week_start', 'mood')->orderBy('week_start')->orderBy('mood')->get();
        $result = [];
        foreach ($rows->groupBy('week_start') as $date => $moods) {
            $count = (int) $moods->sum('entry_count');
            $breakdown = [];
            foreach ($moods as $mood) {
                $breakdown[$mood->mood] = (int) $mood->entry_count;
            }
            // Alphabetical mood order resolves equal-frequency ties consistently.
            $result[] = [
                'week_start' => $date,
                'avg_intensity' => round((float) $moods->sum('total_intensity') / $count, 2),
                'entry_count' => $count,
                'top_mood' => array_keys($breakdown, max($breakdown))[0],
                'mood_breakdown' => (object) $breakdown,
            ];
        }

        return $this->respond('Weekly insights retrieved.', ['weeks' => $result]);
    }

    public function triggers(Request $request): JsonResponse
    {
        [$days, $start, $end] = $this->dayRange($request);
        // One projected query for the validated date range; the model decodes JSON.
        $entries = $this->between($request, $start, $end)->get(['intensity', 'triggers']);
        $totals = [];
        foreach ($entries as $entry) {
            $names = [];
            foreach (is_array($entry->triggers) ? $entry->triggers : [] as $trigger) {
                if (! is_string($trigger) || trim($trigger) === '') {
                    continue;
                }
                $names[trim($trigger)] = true;
            }
            // A repeated trigger in the same entry contributes once to both metrics.
            foreach ($names as $name => $_) {
                $totals[$name] ??= ['name' => (string) $name, 'count' => 0, 'total_intensity' => 0];
                $totals[$name]['count']++;
                $totals[$name]['total_intensity'] += $entry->intensity;
            }
        }
        $triggers = array_map(fn (array $row) => [
            'name' => $row['name'],
            'count' => $row['count'],
            'avg_intensity' => round($row['total_intensity'] / $row['count'], 2),
        ], array_values($totals));
        usort($triggers, fn (array $a, array $b) => ($b['count'] <=> $a['count']) ?: strcmp($a['name'], $b['name']));

        return $this->respond('Trigger insights retrieved.', ['range_days' => $days, 'triggers' => $triggers]);
    }

    public function sleepMood(Request $request): JsonResponse
    {
        [$days, $start, $end] = $this->dayRange($request);
        $points = $this->between($request, $start, $end)->whereBetween('sleep_quality', [1, 5])
            ->selectRaw('sleep_quality, AVG(intensity) AS avg_intensity, COUNT(*) AS count')
            ->groupBy('sleep_quality')->orderBy('sleep_quality')->get()
            ->map(fn (MoodEntry $row) => [
                'sleep_quality' => (int) $row->sleep_quality,
                'avg_intensity' => round((float) $row->avg_intensity, 2),
                'count' => (int) $row->count,
            ])->all();

        return $this->respond('Sleep-mood insights retrieved.', ['range_days' => $days, 'points' => $points]);
    }

    private function dayRange(Request $request): array
    {
        $validated = $request->validate(['days' => ['sometimes', 'integer', 'in:7,30,90']]);
        $days = (int) ($validated['days'] ?? 30);
        $end = CarbonImmutable::instance($request->user()->localToday())->addDay();

        return [$days, $end->subDays($days), $end];
    }

    /** Half-open UTC boundaries preserve local calendar ranges across DST. */
    private function between(Request $request, CarbonImmutable $start, CarbonImmutable $end): Builder
    {
        $utcStart = $start->utc()->toDateTimeString();
        $utcEnd = $end->utc()->toDateTimeString();

        return MoodEntry::query()->where('user_id', $request->user()->id)->where('is_deleted', false)
            ->where(function (Builder $query) use ($utcStart, $utcEnd) {
                $query->where(function (Builder $dated) use ($utcStart, $utcEnd) {
                    $dated->where('client_created_at', '>=', $utcStart)->where('client_created_at', '<', $utcEnd);
                })->orWhere(function (Builder $legacy) use ($utcStart, $utcEnd) {
                    $legacy->whereNull('client_created_at')->where('created_at', '>=', $utcStart)->where('created_at', '<', $utcEnd);
                });
            });
    }

    private function respond(string $message, array $data): JsonResponse
    {
        return response()->json(['success' => true, 'message' => $message, 'data' => $data]);
    }
}
