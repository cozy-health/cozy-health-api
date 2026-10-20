<?php

namespace App\Services\Analytics;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class AdminMetrics
{
    public const FEATURES = ['mood_entries', 'journal_entries', 'quiz_attempts'];
    public const RANGES = ['7d' => 7, '30d' => 30, '90d' => 90];

    public function snapshot(): array
    {
        // Immutable monthly releases prevent repeat queries from differencing live edits.
        $end = CarbonImmutable::now('UTC')->startOfMonth();
        $key = 'admin-metrics:v1:'.$end->format('Y-m');
        return Cache::lock($key.':lock', 300)->block(10, fn () => DB::transaction(function () use ($key, $end) {
            $stored = DB::table('admin_metric_releases')->where('release_key', $key)->first();
            if ($stored) return json_decode($stored->payload, true, flags: JSON_THROW_ON_ERROR);
            $payload = $this->build($end);
            DB::table('admin_metric_releases')->insert(['release_key' => $key, 'payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'created_at' => now('UTC')]);
            return $payload;
        }, 3));
    }

    private function consumers($query)
    {
        return $query->whereNull('users.deleted_at')->where('users.role', 'user')
            ->where('users.is_demo', false)->where('users.is_active', true);
    }

    private function records(string $table, CarbonImmutable $start, CarbonImmutable $end)
    {
        $query = $this->consumers(DB::table($table)->join('users', 'users.id', '=', "$table.user_id"))
            ->whereNull("$table.deleted_at")->where("$table.is_deleted", false)
            ->where("$table.created_at", '>=', $start)->where("$table.created_at", '<', $end);
        if ($table === 'journal_entries') $query->where('journal_entries.is_draft', false);
        if ($table === 'quiz_attempts') $query->whereNotNull('quiz_attempts.completed_at');
        return $query;
    }

    private function cell(int $count, int $distinct, bool $safe = true): array
    {
        if (! $safe || $distinct < 5) return ['status' => 'suppressed', 'count' => '<5'];
        return ['status' => 'available', 'count' => intdiv($count, 5) * 5];
    }

    private function build(CarbonImmutable $end): array
    {
        $start = $end->subDays(90);
        $activity = [];
        $segments = [];
        foreach ([[7, 0], [30, 7], [90, 30]] as [$older, $newer]) {
            $segments[] = ['start' => $end->subDays($older), 'end' => $end->subDays($newer)];
        }
        $usage = [];
        $safety = array_fill(0, 3, ['count' => 0, 'flagged' => [], 'all' => []]);
        foreach (self::FEATURES as $table) {
            $feature = array_fill(0, 3, ['count' => 0, 'users' => []]);
            // Read identifiers only internally for distinct-user proof; no text/answers loaded.
            foreach ($this->records($table, $start, $end)->select("$table.user_id", "$table.created_at", "$table.is_crisis_flagged")->cursor() as $row) {
                $day = substr($row->created_at, 0, 10);
                $activity[$day][$row->user_id] = true;
                foreach ($segments as $i => $segment) {
                    if ($row->created_at < $segment['start']->toDateTimeString() || $row->created_at >= $segment['end']->toDateTimeString()) continue;
                    $feature[$i]['count']++;
                    $feature[$i]['users'][$row->user_id] = true;
                    $safety[$i]['all'][$row->user_id] = true;
                    if ($row->is_crisis_flagged) {
                        $safety[$i]['count']++;
                        $safety[$i]['flagged'][$row->user_id] = true;
                    }
                }
            }
            // One release decision covers all overlapping range totals for this feature.
            $safe = ! array_filter($feature, fn ($part) => count($part['users']) < 5);
            foreach (self::RANGES as $range => $days) {
                $n = $days === 7 ? 1 : ($days === 30 ? 2 : 3);
                $usage[$range][] = ['feature' => $table, ...$this->cell(array_sum(array_map(fn ($part) => intdiv($part['count'], 5) * 5, array_slice($feature, 0, $n))), 5, $safe)];
            }
        }
        $safetySafe = ! array_filter($safety, fn ($part) => count($part['flagged']) < 5
            || count(array_diff_key($part['all'], $part['flagged'])) < 5);
        $safetyRanges = [];
        foreach (self::RANGES as $range => $days) {
            $n = $days === 7 ? 1 : ($days === 30 ? 2 : 3);
            $safetyRanges[] = ['range' => $range, ...$this->cell(array_sum(array_map(fn ($part) => intdiv($part['count'], 5) * 5, array_slice($safety, 0, $n))), 5, $safetySafe)];
        }
        $daily = [];
        for ($day = $end->subDays(30); $day->lt($end); $day = $day->addDay()) {
            $distinct = count($activity[$day->toDateString()] ?? []);
            $daily[] = ['date' => $day->toDateString(), ...$this->cell($distinct, $distinct)];
        }
        $cohorts = [];
        $weekEnd = $end->startOfWeek();
        for ($week = $weekEnd->subWeeks(12); $week->lt($weekEnd); $week = $week->addWeek()) {
            $users = $this->consumers(DB::table('users'))->where('created_at', '>=', $week)
                ->where('created_at', '<', $week->addWeek())->select('id', 'created_at')->get();
            $cells = [];
            $patterns = [];
            foreach ($users as $user) {
                $pattern = '';
                foreach ([1, 7, 30] as $offset) {
                    if ($week->addWeek()->addDays($offset)->gt($end)) continue;
                    $target = CarbonImmutable::parse($user->created_at, 'UTC')->startOfDay()->addDays($offset)->toDateString();
                    $pattern .= isset($activity[$target][$user->id]) ? '1' : '0';
                }
                $patterns[$pattern] = ($patterns[$pattern] ?? 0) + 1;
            }
            foreach ([1, 7, 30] as $offset) {
                if ($week->addWeek()->addDays($offset)->gt($end)) {
                    $cells['d'.$offset] = ['status' => 'immature', 'count' => null, 'cohort' => null, 'rate' => null];
                    continue;
                }
                $retained = 0;
                foreach ($users as $user) {
                    $target = CarbonImmutable::parse($user->created_at, 'UTC')->startOfDay()->addDays($offset)->toDateString();
                    if (isset($activity[$target][$user->id])) $retained++;
                }
                $total = $users->count();
                $safe = $retained >= 5 && $total - $retained >= 5;
                $cell = $this->cell($retained, $retained, $safe);
                // No exact denominator/rate that could undo rounding or reveal a complement.
                $cells['d'.$offset] = [...$cell, 'cohort' => $safe ? intdiv($total, 5) * 5 : '<5>',
                    'rate' => $safe ? round((intdiv($retained, 5) * 5) / (intdiv($total, 5) * 5) * 100) : null];
            }
            // Joint suppression across horizons prevents reconstructing a small retained set.
            $jointSafe = ! array_filter($cells, fn ($cell) => $cell['status'] === 'suppressed')
                && ! array_filter($patterns, fn ($size) => $size < 5);
            if (! $jointSafe) foreach ($cells as &$cell) {
                if ($cell['status'] !== 'immature') $cell = ['status' => 'suppressed', 'count' => '<5', 'cohort' => '<5', 'rate' => null];
            }
            unset($cell);
            $cohorts[] = ['week' => $week->toDateString(), 'signups' => $this->cell($users->count(), $users->count(), $jointSafe), 'retention' => $cells];
        }
        return ['release' => $end->toDateString(), 'timezone' => 'UTC', 'minimum_cohort' => 5,
            'precision' => 'Counts rounded down to multiples of 5; rates use released rounded counts.',
            'window' => ['from' => $start->toDateString(), 'to_exclusive' => $end->toDateString()],
            'overview' => ['daily_activity' => $daily, 'signup_trend' => array_map(fn ($row) => ['week' => $row['week'], ...$row['signups']], $cohorts),
                'retention_summary' => $cohorts],
            'usage' => $usage, 'retention' => $cohorts, 'safety' => $safetyRanges,
            'unavailable_features' => ['app_opens', 'logins', 'crisis_hub_opens'],
            'activity_definition' => 'UTC server creation day of retained non-deleted moods, published journals or completed quiz records; consumer accounts only.'];
    }
}
