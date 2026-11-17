<?php

namespace App\Services\Provider;

use App\Models\MoodEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ProviderMoodInsights
{
    private const NEGATIVE = ['angry', 'sad', 'upset', 'anxious', 'low'];
    private const POSITIVE = ['good', 'spectacular', 'happy', 'calm', 'excited'];
    private const NEUTRAL = ['okay'];

    public function rosterSummaries(Collection $links): array
    {
        $shared = $links->where('consent_moods', true);
        if ($shared->isEmpty()) return [];

        $userIds = $shared->pluck('user_id')->unique()->values();
        $timestamp = DB::raw('COALESCE(client_created_at, created_at)');
        // Covers every possible patient-local seven-day window (UTC offsets are at most 14 hours).
        $entries = MoodEntry::withoutGlobalScope('user')->whereIn('user_id', $userIds)
            ->where('is_deleted', false)->whereBetween($timestamp, [now('UTC')->subDays(8), now('UTC')])
            ->get(['user_id', 'mood', 'intensity', 'triggers', 'custom_trigger', 'coping_strategies', 'client_created_at', 'created_at']);
        $latest = MoodEntry::withoutGlobalScope('user')->whereIn('user_id', $userIds)->where('is_deleted', false)->where($timestamp, '<=', now('UTC'))
            ->select('user_id')->selectRaw('MAX(COALESCE(client_created_at, created_at)) AS latest_check_in')
            ->groupBy('user_id')->pluck('latest_check_in', 'user_id');

        $summaries = [];
        foreach ($shared as $link) {
            $timezone = $this->timezone($link->user->timezone ?? null);
            $period = $this->period('week', $timezone);
            $summary = $this->aggregate($entries->where('user_id', $link->user_id), $period);
            $summary['latest_check_in'] = isset($latest[$link->user_id])
                ? CarbonImmutable::parse($latest[$link->user_id], 'UTC')->toIso8601String() : null;
            $summaries[$link->user_id] = $summary;
        }

        return $summaries;
    }

    public function patientInsights($link, string $periodKey): array
    {
        $timezone = $this->timezone($link->user->timezone ?? null);
        $period = $this->period($periodKey, $timezone);
        $entries = MoodEntry::withoutGlobalScope('user')->where('user_id', $link->user_id)->where('is_deleted', false)
            ->whereBetween(DB::raw('COALESCE(client_created_at, created_at)'), [$period['start_utc'], $period['end_utc']->min(now('UTC'))])
            ->get(['mood', 'intensity', 'triggers', 'custom_trigger', 'coping_strategies', 'client_created_at', 'created_at']);
        $result = $this->aggregate($entries, $period, true);
        if ($periodKey !== 'week') {
            $week = $this->period('week', $timezone);
            $weekEntries = MoodEntry::withoutGlobalScope('user')->where('user_id', $link->user_id)->where('is_deleted', false)
                ->whereBetween(DB::raw('COALESCE(client_created_at, created_at)'), [$week['start_utc'], $week['end_utc']->min(now('UTC'))])
                ->get(['mood', 'intensity', 'triggers', 'custom_trigger', 'coping_strategies', 'client_created_at', 'created_at']);
            $result['current_priority'] = $this->priority($this->classifiedDays($weekEntries, $week), $weekEntries->count());
            $result['current_priority']['period'] = $this->publicPeriod($week);
        } else {
            $result['current_priority'] = $result['priority'];
            $result['current_priority']['period'] = $result['period'];
        }

        return $result;
    }

    public function utcBoundsForDates(?string $timezone, string $from, string $to): array
    {
        $zone = $this->timezone($timezone);
        return [
            CarbonImmutable::createFromFormat('Y-m-d H:i:s', "$from 00:00:00", $zone)->utc(),
            CarbonImmutable::createFromFormat('Y-m-d H:i:s', "$to 23:59:59", $zone)->utc(),
        ];
    }

    private function aggregate(Collection $entries, array $period, bool $withBuckets = false): array
    {
        $entries = $entries->filter(fn ($entry) => $this->inside($entry, $period))->values();
        $days = $this->classifiedDays($entries, $period);
        $counts = ['positive' => 0, 'negative' => 0, 'neutral' => 0, 'unclassified' => 0];
        $moods = [];
        $triggers = [];
        $coping = [];
        $intensities = [];
        foreach ($entries as $entry) {
            $mood = strtolower(trim((string) $entry->mood));
            $class = $this->classification($mood);
            $counts[$class]++;
            $moods[$mood ?: 'unknown'] = ($moods[$mood ?: 'unknown'] ?? 0) + 1;
            if (is_numeric($entry->intensity)) $intensities[] = (float) $entry->intensity;
            foreach ($this->values($entry->triggers, $entry->custom_trigger) as $value) $triggers[$value] = ($triggers[$value] ?? 0) + 1;
            foreach ($this->values($entry->coping_strategies) as $value) $coping[$value] = ($coping[$value] ?? 0) + 1;
        }
        $priority = $this->priority($days, $entries->count());
        $result = [
            'period' => $this->publicPeriod($period),
            'priority' => $priority,
            'coverage' => ['check_ins' => $entries->count(), 'recorded_days' => count($days['recorded']), 'possible_days' => $period['days']],
            'counts' => $counts,
            'average_intensity' => $intensities ? round(array_sum($intensities) / count($intensities), 1) : null,
            'moods' => $this->ranked($moods),
            'triggers' => $this->ranked($triggers, 8),
            'coping_strategies' => $this->ranked($coping, 8),
        ];
        if ($withBuckets) {
            $result['buckets'] = $this->buckets($entries, $period);
            $populated = array_values(array_filter($result['buckets'], fn ($bucket) => $bucket['check_ins'] > 0));
            $result['trend'] = count($populated) >= 2 ? $this->trend($populated[count($populated) - 2], $populated[count($populated) - 1]) : null;
        }
        return $result;
    }

    private function period(string $key, string $timezone): array
    {
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $start = match ($key) {
            'month' => $today->startOfMonth(),
            'year' => $today->startOfYear(),
            default => $today->subDays(6),
        };
        $end = $today->endOfDay();
        return ['key' => $key, 'label' => match ($key) { 'month' => 'This month', 'year' => 'This year', default => 'Week' },
            'timezone' => $timezone, 'start' => $start, 'end' => $end, 'start_utc' => $start->utc(), 'end_utc' => $end->utc(),
            'days' => (int) $start->diffInDays($today) + 1, 'bucket_unit' => $key === 'year' ? 'month' : 'day'];
    }

    private function publicPeriod(array $period): array
    {
        return ['key' => $period['key'], 'label' => $period['label'], 'start' => $period['start']->format('Y-m-d'),
            'end' => $period['end']->format('Y-m-d'), 'timezone' => $period['timezone'], 'bucket_unit' => $period['bucket_unit']];
    }

    private function classifiedDays(Collection $entries, array $period): array
    {
        $recorded = [];
        $negative = [];
        foreach ($entries as $entry) {
            if (! $this->inside($entry, $period)) continue;
            $day = $this->localTimestamp($entry, $period['timezone'])->format('Y-m-d');
            $recorded[$day] = true;
            if ($this->classification(strtolower(trim((string) $entry->mood))) === 'negative') $negative[$day] = true;
        }
        return ['recorded' => array_keys($recorded), 'negative' => array_keys($negative)];
    }

    private function priority(array $days, int $checkIns): array
    {
        $negative = count($days['negative']);
        if ($checkIns === 0) return ['level' => null, 'status' => 'no_checkins', 'negative_days' => 0, 'explanation' => 'No check-ins in the last 7 days.'];
        if ($negative === 0) return ['level' => null, 'status' => 'no_negative', 'negative_days' => 0, 'explanation' => 'No negative days recorded in the last 7 days.'];
        $level = $negative >= 5 ? 'high' : ($negative >= 3 ? 'moderate' : 'low');
        return ['level' => $level, 'status' => $level, 'negative_days' => $negative,
            'explanation' => "$negative negative ".($negative === 1 ? 'day' : 'days').' in the last 7 days.'];
    }

    private function buckets(Collection $entries, array $period): array
    {
        $buckets = [];
        $cursor = $period['start'];
        while ($cursor->lte($period['end'])) {
            $start = $period['bucket_unit'] === 'month' ? $cursor->startOfMonth() : $cursor->startOfDay();
            $end = $period['bucket_unit'] === 'month' ? $cursor->endOfMonth()->min($period['end']) : $cursor->endOfDay();
            $key = $period['bucket_unit'] === 'month' ? $start->format('Y-m') : $start->format('Y-m-d');
            $buckets[$key] = ['key' => $key, 'label' => $period['bucket_unit'] === 'month' ? $start->format('M') : $start->format('M j'),
                'start' => $start->format('Y-m-d'), 'end' => $end->format('Y-m-d'), 'check_ins' => 0,
                'positive' => 0, 'negative' => 0, 'neutral' => 0, 'unclassified' => 0, '_intensity' => []];
            $cursor = $period['bucket_unit'] === 'month' ? $cursor->addMonth()->startOfMonth() : $cursor->addDay();
        }
        foreach ($entries as $entry) {
            $local = $this->localTimestamp($entry, $period['timezone']);
            $key = $period['bucket_unit'] === 'month' ? $local->format('Y-m') : $local->format('Y-m-d');
            if (! isset($buckets[$key])) continue;
            $class = $this->classification(strtolower(trim((string) $entry->mood)));
            $buckets[$key]['check_ins']++;
            $buckets[$key][$class]++;
            if (is_numeric($entry->intensity)) $buckets[$key]['_intensity'][] = (float) $entry->intensity;
        }
        return array_values(array_map(function ($bucket) {
            $values = $bucket['_intensity'];
            unset($bucket['_intensity']);
            $bucket['average_intensity'] = $values ? round(array_sum($values) / count($values), 1) : null;
            return $bucket;
        }, $buckets));
    }

    private function inside($entry, array $period): bool
    {
        $timestamp = $this->localTimestamp($entry, $period['timezone']);
        return $timestamp->betweenIncluded($period['start'], $period['end']);
    }

    private function trend(array $previous, array $latest): array
    {
        return ['previous_bucket' => $previous['label'], 'latest_bucket' => $latest['label'],
            'check_in_change' => $latest['check_ins'] - $previous['check_ins'],
            'intensity_change' => $previous['average_intensity'] !== null && $latest['average_intensity'] !== null
                ? round($latest['average_intensity'] - $previous['average_intensity'], 1) : null];
    }

    private function localTimestamp($entry, string $timezone): CarbonImmutable
    {
        return CarbonImmutable::parse($entry->client_created_at ?? $entry->created_at, 'UTC')->setTimezone($timezone);
    }

    private function classification(string $mood): string
    {
        if (in_array($mood, self::NEGATIVE, true)) return 'negative';
        if (in_array($mood, self::POSITIVE, true)) return 'positive';
        if (in_array($mood, self::NEUTRAL, true)) return 'neutral';
        return 'unclassified';
    }

    private function values($values, ?string $custom = null): array
    {
        $items = is_array($values) ? $values : [];
        if ($custom) $items[] = $custom;
        return array_values(array_unique(array_filter(array_map(fn ($value) => trim((string) $value), $items))));
    }

    private function ranked(array $counts, ?int $limit = null): array
    {
        arsort($counts);
        $rows = array_map(fn ($name, $count) => ['name' => $name, 'count' => $count], array_keys($counts), array_values($counts));
        return $limit ? array_slice($rows, 0, $limit) : $rows;
    }

    private function timezone(?string $timezone): string
    {
        try {
            return (new \DateTimeZone((string) $timezone))->getName();
        } catch (\Throwable) {
            return 'UTC';
        }
    }
}
