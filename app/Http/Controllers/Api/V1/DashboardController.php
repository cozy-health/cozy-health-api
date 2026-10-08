<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MoodEntry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    private const ENTRY_TIME = 'COALESCE(client_created_at, created_at)';

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $today = CarbonImmutable::instance($user->localToday());
        $end = $today->addDay();
        $weekly = $this->dailyStats($user->id, $today->subDays(6), $end);
        $fields = ['id', 'mood', 'intensity', 'created_at', 'client_created_at'];

        $todayMood = $this->between($user->id, $today, $end)
            ->orderByRaw(self::ENTRY_TIME.' DESC')->orderByDesc('id')
            ->first($fields);

        $recent = $this->entries($user->id)
            ->where(function (Builder $query) use ($end) {
                $utcEnd = $end->utc()->toDateTimeString();
                $query->where('client_created_at', '<', $utcEnd)
                    ->orWhere(function (Builder $legacy) use ($utcEnd) {
                        $legacy->whereNull('client_created_at')->where('created_at', '<', $utcEnd);
                    });
            })
            ->orderByRaw(self::ENTRY_TIME.' DESC')->orderByDesc('id')
            ->limit(5)->get($fields);

        return response()->json([
            'success' => true,
            'message' => 'Dashboard retrieved.',
            'data' => [
                'today_mood' => $todayMood,
                'streak_days' => $this->calculateStreak($user->id, $today, $weekly),
                'weekly_moods' => $weekly,
                'recent_entries' => $recent,
                'timezone' => $user->timezone,
            ],
        ]);
    }

    private function entries(int $userId): Builder
    {
        return MoodEntry::query()->where('user_id', $userId)->where('is_deleted', false);
    }

    /** UTC ranges remain half-open, including across 23/25-hour local days. */
    private function between(int $userId, CarbonImmutable $start, CarbonImmutable $end): Builder
    {
        $utcStart = $start->utc()->toDateTimeString();
        $utcEnd = $end->utc()->toDateTimeString();

        return $this->entries($userId)->where(function (Builder $query) use ($utcStart, $utcEnd) {
            $query->where(function (Builder $dated) use ($utcStart, $utcEnd) {
                $dated->where('client_created_at', '>=', $utcStart)->where('client_created_at', '<', $utcEnd);
            })->orWhere(function (Builder $legacy) use ($utcStart, $utcEnd) {
                $legacy->whereNull('client_created_at')
                    ->where('created_at', '>=', $utcStart)->where('created_at', '<', $utcEnd);
            });
        });
    }

    /** Aggregate only occupied local dates, without database timezone tables. */
    private function dailyStats(int $userId, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $case = 'CASE';
        $bindings = [];
        for ($day = $start; $day->lessThan($end); $day = $day->addDay()) {
            $case .= ' WHEN '.self::ENTRY_TIME.' >= ? AND '.self::ENTRY_TIME.' < ? THEN ?';
            array_push($bindings, $day->utc()->toDateTimeString(), $day->addDay()->utc()->toDateTimeString(), $day->toDateString());
        }

        return $this->between($userId, $start, $end)
            ->selectRaw($case.' END AS day', $bindings)
            ->selectRaw('COUNT(*) AS count, AVG(intensity) AS avg_intensity')
            ->groupBy('day')->orderBy('day')->get()
            ->map(fn (MoodEntry $row) => [
                'day' => $row->day,
                'count' => (int) $row->count,
                'avg_intensity' => round((float) $row->avg_intensity, 2),
            ])->all();
    }

    private function calculateStreak(int $userId, CarbonImmutable $today, array $weekly): int
    {
        $days = array_fill_keys(array_column($weekly, 'day'), true);
        $day = isset($days[$today->toDateString()]) ? $today : $today->subDay();
        $loadedStart = $today->subDays(6);
        $streak = 0;

        while (true) {
            if ($day->lessThan($loadedStart)) {
                // Read at most 31 aggregate dates per query, never raw history.
                $loadedStart = $day->subDays(30);
                $history = $this->dailyStats($userId, $loadedStart, $day->addDay());
                $days = array_fill_keys(array_column($history, 'day'), true);
            }
            if (! isset($days[$day->toDateString()])) {
                return $streak;
            }
            $streak++;
            $day = $day->subDay();
        }
    }
}
