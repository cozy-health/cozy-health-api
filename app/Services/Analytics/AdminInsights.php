<?php

namespace App\Services\Analytics;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Fixed releases and joint suppression prevent overlapping filters exposing small cohorts. */
class AdminInsights
{
    public function snapshot(): array
    {
        $end = CarbonImmutable::now('UTC')->startOfMonth();
        $key = 'admin-insights:v1:'.$end->format('Y-m');
        return Cache::lock($key.':lock', 300)->block(10, fn () => DB::transaction(function () use ($key, $end) {
            $stored = DB::table('admin_metric_releases')->where('release_key', $key)->first();
            if ($stored) return json_decode($stored->payload, true, flags: JSON_THROW_ON_ERROR);
            $payload = $this->build($end);
            DB::table('admin_metric_releases')->insert(['release_key' => $key, 'payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'created_at' => now()]);
            return $payload;
        }, 3));
    }

    private function consumers($query)
    {
        return $query->whereNull('users.deleted_at')->where('users.role', 'user')->where('users.is_demo', false)->where('users.is_active', true);
    }

    private function build(CarbonImmutable $end): array
    {
        $start = $end->subDays(90);
        $buckets = [];
        $daily = [];
        $signups = [];
        $activity = [];
        $intensity = [];
        // Only structured choices are read. No notes, custom triggers, bodies or answers.
        $rows = $this->consumers(DB::table('mood_entries')->join('users', 'users.id', '=', 'mood_entries.user_id'))
            ->whereNull('mood_entries.deleted_at')->where('mood_entries.is_deleted', false)
            ->where('mood_entries.created_at', '>=', $start)->where('mood_entries.created_at', '<', $end)
            ->select('mood_entries.user_id', 'mood_entries.created_at', 'mood_entries.mood', 'mood_entries.intensity', 'mood_entries.triggers', 'mood_entries.coping_strategies', 'mood_entries.coping_helped')->cursor();
        $knownTriggers = DB::table('feeling_causes')->pluck('name', 'slug')->all();
        $knownCoping = DB::table('coping_mechanisms')->pluck('name', 'slug')->all();
        $moods = DB::table('feelings')->pluck('name', 'slug')->all();
        foreach ($knownTriggers as $name) $knownTriggers[\Illuminate\Support\Str::slug($name)] = $name;
        foreach ($knownCoping as $name) $knownCoping[\Illuminate\Support\Str::slug($name)] = $name;
        foreach (DB::table('wellness_catalog_aliases')->get() as $alias) {
            if ($alias->resource === 'triggers' && isset($knownTriggers[$alias->slug])) $knownTriggers[$alias->alias] = $knownTriggers[$alias->slug];
            if ($alias->resource === 'coping' && isset($knownCoping[$alias->slug])) $knownCoping[$alias->alias] = $knownCoping[$alias->slug];
        }
        foreach ($rows as $row) {
            $date = substr($row->created_at, 0, 10);
            $segment = $date >= $end->subDays(7)->toDateString() ? 0 : ($date >= $end->subDays(30)->toDateString() ? 1 : 2);
            $mood = strtolower(trim($row->mood));
            $this->add($intensity, 'cohort', 'intensity', $segment, $row->user_id);
            $intensity['cohort']['intensity'][$segment]['sum'] = ($intensity['cohort']['intensity'][$segment]['sum'] ?? 0) + $row->intensity;
            // Allowlisted catalog choices only; do not turn arbitrary patient text into admin labels.
            if (isset($moods[$mood])) {
                $this->add($buckets, 'moods', $moods[$mood], $segment, $row->user_id);
                $this->add($daily, 'moods', $date.'|'.$moods[$mood], 0, $row->user_id);
                if ($mood === 'anxious') $this->add($buckets, 'anxiety', substr($row->created_at, 11, 2).':00', $segment, $row->user_id);
            }
            foreach (['triggers' => [$row->triggers, $knownTriggers], 'coping' => [$row->coping_strategies, $knownCoping]] as $kind => [$json, $catalog]) {
                $choices = json_decode($json ?? '[]', true);
                if (! is_array($choices)) continue;
                $seen = [];
                foreach (array_unique(array_filter($choices, 'is_string')) as $choice) {
                    $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', trim($choice)));
                    $slug = trim($slug, '-');
                    if (! isset($catalog[$slug]) || isset($seen[$catalog[$slug]])) continue;
                    $seen[$catalog[$slug]] = true;
                    $this->add($buckets, $kind, $catalog[$slug], $segment, $row->user_id);
                    if ($kind === 'coping' && in_array($row->coping_helped, ['yes', 'somewhat', 'no'], true)) {
                        $this->add($buckets, 'helpfulness', $catalog[$slug].'|'.$row->coping_helped, $segment, $row->user_id);
                    }
                }
            }
        }
        foreach (AdminMetrics::FEATURES as $table) {
            $query = $this->consumers(DB::table($table)->join('users', 'users.id', '=', "$table.user_id"))
                ->whereNull("$table.deleted_at")->where("$table.is_deleted", false)
                ->where("$table.created_at", '>=', $start)->where("$table.created_at", '<', $end);
            if ($table === 'journal_entries') $query->where('is_draft', false);
            if ($table === 'quiz_attempts') $query->whereNotNull('completed_at');
            foreach ($query->select("$table.user_id", "$table.created_at")->cursor() as $row) {
                $activity[substr($row->created_at, 0, 10)][$row->user_id] = true;
            }
        }
        foreach ($this->consumers(DB::table('users'))->where('created_at', '>=', $start)->where('created_at', '<', $end)->select('id', 'created_at')->cursor() as $row) {
            $signups[substr($row->created_at, 0, 10)][$row->id] = true;
        }
        $ranges = [];
        foreach (['enrollments' => ['challenge_enrollments', 'created_at'], 'completions' => ['challenge_completions', 'completed_at']] as $kind => [$table, $time]) {
            $query = DB::table($table);
            if ($kind === 'completions') $query->join('challenge_enrollments', 'challenge_enrollments.id', '=', 'challenge_completions.enrollment_id');
            $query->join('users', 'users.id', '=', 'challenge_enrollments.user_id')->join('wellness_challenges', 'wellness_challenges.id', '=', 'challenge_enrollments.challenge_id');
            foreach ($this->consumers($query)->where("$table.$time", '>=', $start)->where("$table.$time", '<', $end)
                ->select('challenge_enrollments.user_id', 'wellness_challenges.name', "$table.$time as occurred")->cursor() as $row) {
                $segment = $row->occurred >= $end->subDays(7)->toDateTimeString() ? 0 : ($row->occurred >= $end->subDays(30)->toDateTimeString() ? 1 : 2);
                $this->add($buckets, $kind, $row->name, $segment, $row->user_id);
            }
        }
        foreach ($this->consumers(DB::table('subscription_events')->join('users', 'users.id', '=', 'subscription_events.user_id'))
            ->where('subscription_events.created_at', '>=', $start)->where('subscription_events.created_at', '<', $end)->select('subscription_events.user_id', 'subscription_events.status', 'subscription_events.created_at')->cursor() as $row) {
            $segment = $row->created_at >= $end->subDays(7)->toDateTimeString() ? 0 : ($row->created_at >= $end->subDays(30)->toDateTimeString() ? 1 : 2);
            if (in_array($row->status, ['active', 'inactive', 'cancelled', 'expired', 'trial'], true)) $this->add($buckets, 'subscription_changes', $row->status, $segment, $row->user_id);
        }
        $summaryBuckets = [];
        foreach (['active_users' => $activity, 'new_users' => $signups] as $kind => $dates) {
            $parts = [];
            foreach ($dates as $date => $users) {
                $segment = $date >= $end->subDays(7)->toDateString() ? 0 : ($date >= $end->subDays(30)->toDateString() ? 1 : 2);
                $parts[$segment] = ($parts[$segment] ?? []) + $users;
            }
            foreach ($parts as $segment => $users) foreach ($users as $id => $_) $this->add($summaryBuckets, $kind, $kind, $segment, $id);
        }
        foreach ($this->consumers(DB::table('community_posts')->join('users', 'users.id', '=', 'community_posts.user_id'))
            ->whereNull('community_posts.deleted_at')->where('community_posts.status', 'published')
            ->where('community_posts.created_at', '>=', $start)->where('community_posts.created_at', '<', $end)
            ->select('community_posts.user_id', 'community_posts.created_at')->cursor() as $post) {
            $segment = $post->created_at >= $end->subDays(7)->toDateTimeString() ? 0 : ($post->created_at >= $end->subDays(30)->toDateTimeString() ? 1 : 2);
            $this->add($summaryBuckets, 'community_posts', 'community_posts', $segment, $post->user_id);
        }
        $pending = $this->consumers(DB::table('support_reports')->join('users', 'users.id', '=', 'support_reports.user_id'))
            ->where('support_reports.status', 'open')->where('support_reports.created_at', '>=', $start)->where('support_reports.created_at', '<', $end)
            ->select('support_reports.user_id', 'support_reports.created_at')->get();
        foreach (['post' => 'community_posts', 'comment' => 'community_comments'] as $kind => $table) {
            $pending = $pending->concat($this->consumers(DB::table('community_reports')->join($table, "$table.id", '=', 'community_reports.target_id')->join('users', 'users.id', '=', "$table.user_id"))
                ->where('community_reports.target_type', $kind)->where('community_reports.status', 'pending')->whereNull("$table.deleted_at")
                ->where('community_reports.created_at', '>=', $start)->where('community_reports.created_at', '<', $end)
                ->select("$table.user_id", 'community_reports.created_at')->get());
        }
        foreach ($pending as $report) {
            $segment = $report->created_at >= $end->subDays(7)->toDateTimeString() ? 0 : ($report->created_at >= $end->subDays(30)->toDateTimeString() ? 1 : 2);
            $this->add($summaryBuckets, 'pending_reports', 'pending_reports', $segment, $report->user_id);
        }
        $devices = [];
        foreach ($this->consumers(DB::table('push_devices')->join('users', 'users.id', '=', 'push_devices.user_id'))
            ->where('push_devices.expires_at', '>=', $end)->where('push_devices.created_at', '<', $end)->select('push_devices.platform', 'push_devices.user_id')->cursor() as $device) {
            if (in_array($device->platform, ['android', 'ios', 'web'], true)) $this->add($devices, 'platforms', $device->platform, 0, $device->user_id);
        }
        foreach (AdminMetrics::RANGES as $range => $days) {
            $n = $days === 7 ? 1 : ($days === 30 ? 2 : 3);
            $data = [];
            foreach (['moods', 'triggers', 'coping', 'anxiety', 'helpfulness', 'enrollments', 'completions', 'subscription_changes'] as $kind) {
                $data[$kind] = $this->release($buckets[$kind] ?? [], $n);
            }
            $data['daily'] = [];
            for ($date = $end->subDays($days); $date->lt($end); $date = $date->addDay()) {
                $day = $date->toDateString();
                $data['daily'][] = ['date' => $day, 'signups' => $this->cell(count($signups[$day] ?? [])), 'active' => $this->cell(count($activity[$day] ?? []))];
            }
            $data['mood_trends'] = array_values(array_filter($this->release($daily['moods'] ?? [], 1, false), fn ($row) => substr($row['label'], 0, 10) >= $end->subDays($days)->toDateString()));
            $data['devices'] = $this->release($devices['platforms'] ?? [], 1, false);
            $activeUsers = []; $newUsers = [];
            foreach (array_slice($data['daily'], 0) as $day) {
                $activeUsers += $activity[$day['date']] ?? [];
                $newUsers += $signups[$day['date']] ?? [];
            }
            $hidden = ['status' => 'suppressed', 'count' => '<5'];
            $data['summary'] = [
                'active_users' => $this->release($summaryBuckets['active_users'] ?? [], $n) ? $this->cell(count($activeUsers)) : $hidden,
                'new_users' => $this->release($summaryBuckets['new_users'] ?? [], $n) ? $this->cell(count($newUsers)) : $hidden,
                'community_posts' => $this->release($summaryBuckets['community_posts'] ?? [], $n)[0] ?? $hidden,
                'pending_reports' => $this->release($summaryBuckets['pending_reports'] ?? [], $n)[0] ?? $hidden,
            ];
            $parts = [];
            for ($i = 0; $i < $n; $i++) $parts[] = $intensity['cohort']['intensity'][$i] ?? ['count' => 0, 'sum' => 0];
            $intensityCount = array_sum(array_column($parts, 'count'));
            $data['summary']['average_intensity'] = $this->release($intensity['cohort'] ?? [], $n) && $intensityCount
                ? ['status' => 'available', 'count' => round(array_sum(array_column($parts, 'sum')) / $intensityCount, 1)] : $hidden;
            $ranges[$range] = $data;
        }
        return ['meta' => ['release' => $end->toDateString(), 'timezone' => 'UTC', 'minimum_cohort' => 5,
            'precision' => 'Frozen monthly release; counts rounded down to multiples of five. Small and related groups are suppressed.',
            'window' => ['from' => $start->toDateString(), 'to_exclusive' => $end->toDateString()]], 'ranges' => $ranges];
    }

    private function add(array &$buckets, string $kind, string $label, int $segment, int $user): void
    {
        $buckets[$kind][$label][$segment]['count'] = ($buckets[$kind][$label][$segment]['count'] ?? 0) + 1;
        $buckets[$kind][$label][$segment]['users'][$user] = true;
    }

    private function cell(int $count): array
    {
        return $count < 5 ? ['status' => 'suppressed', 'count' => '<5'] : ['status' => 'available', 'count' => intdiv($count, 5) * 5];
    }

    private function release(array $groups, int $n, bool $joint = true): array
    {
        $rows = [];
        // Suppress the entire breakdown if any category has a small contributor set.
        $safe = true;
        foreach ($groups as $segments) foreach (range(0, $joint ? 2 : 0) as $i) {
            if (count($segments[$i]['users'] ?? []) < 5) $safe = false;
        }
        if ($joint) foreach ($groups as $segments) {
            $users = [];
            foreach ($segments as $i => $part) foreach ($part['users'] as $id => $_) $users[$id] = ($users[$id] ?? 0) | (1 << $i);
            if (array_filter(array_count_values($users), fn ($count) => $count < 5)) $safe = false;
        }
        foreach ($groups as $label => $segments) {
            $count = 0;
            for ($i = 0; $i < $n; $i++) $count += intdiv($segments[$i]['count'] ?? 0, 5) * 5;
            // Hide labels as well when the category is suppressed.
            if ($safe) $rows[] = ['label' => $label, 'status' => 'available', 'count' => $count];
        }
        usort($rows, fn ($a, $b) => $b['count'] <=> $a['count'] ?: strcmp($a['label'], $b['label']));
        return $rows;
    }
}
