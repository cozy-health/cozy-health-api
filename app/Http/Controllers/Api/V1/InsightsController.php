<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MoodEntry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
        $entries = $this->between($request, $start, $end)->select('id', 'intensity', 'triggers');
        // Expand JSON in SQL and count each trigger once per entry, without raw history reads.
        $expansion = DB::connection()->getDriverName() === 'sqlite'
            ? "json_each(entries.triggers) AS expanded"
            : "JSON_TABLE(entries.triggers, '$[*]' COLUMNS(value VARCHAR(255) PATH '$')) AS expanded";
        $names = DB::query()->fromSub($entries, 'entries')->crossJoin(DB::raw($expansion))
            ->selectRaw('DISTINCT entries.id, entries.intensity, TRIM(expanded.value) AS name')
            ->whereRaw("TRIM(expanded.value) <> ''");
        $triggers = DB::query()->fromSub($names, 'named_triggers')
            ->selectRaw('name, COUNT(*) AS count, AVG(intensity) AS avg_intensity')
            ->groupBy('name')->orderByDesc('count')->orderBy('name')->get()
            ->map(fn ($row) => [
                'name' => $row->name,
                'count' => (int) $row->count,
                'avg_intensity' => round((float) $row->avg_intensity, 2),
            ])->all();

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
