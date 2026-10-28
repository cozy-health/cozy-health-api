<?php

namespace App\Services;

use Carbon\CarbonImmutable;

/** Deterministic replay makes retries and late offline check-ins safe. */
class StreakAccounting
{
    public function calculate(array $dates, string $today, string $earningStarts): array
    {
        $dates = array_values(array_unique(array_filter($dates, fn ($date) => $date <= $today)));
        sort($dates);
        $count = $best = $bank = $run = 0;
        $previous = null;
        $protected = [];
        $milestones = [];
        foreach ($dates as $date) {
            if ($previous !== null) {
                $gap = CarbonImmutable::parse($previous)->diffInDays(CarbonImmutable::parse($date)) - 1;
                if ($gap > 0) {
                    $run = 0;
                    $spent = min($bank, $gap);
                    for ($i = 1; $i <= $spent; $i++) {
                        $protected[] = CarbonImmutable::parse($previous)->addDays($i)->toDateString();
                    }
                    $bank -= $spent;
                    if ($gap > $spent) {
                        $count = 0;
                    }
                }
            }
            $count++;
            $best = max($best, $count);
            foreach ([3, 7, 30] as $target) {
                if ($count >= $target && ! isset($milestones['streak_'.$target])) {
                    $milestones['streak_'.$target] = $date;
                }
            }
            if ($date >= $earningStarts) {
                $run++;
                if ($run === 7) {
                    $bank = min(2, $bank + 1);
                    $run = 0;
                }
            } else {
                $run = 0;
            }
            $previous = $date;
        }
        if ($previous !== null) {
            // Today remains available for a check-in until local midnight.
            $gap = max(0, CarbonImmutable::parse($previous)->diffInDays(CarbonImmutable::parse($today)) - 1);
            if ($gap > 0) {
                $run = 0;
                $spent = min($bank, $gap);
                for ($i = 1; $i <= $spent; $i++) {
                    $protected[] = CarbonImmutable::parse($previous)->addDays($i)->toDateString();
                }
                $bank -= $spent;
                if ($gap > $spent) {
                    $count = 0;
                }
            }
        }

        return ['current_days' => $count, 'best_days' => $best, 'rest_days' => $bank,
            'earning_progress' => $run, 'protected_dates' => $protected,
            'completed_dates' => $dates, 'today_completed' => in_array($today, $dates, true),
            'milestones' => $milestones];
    }
}
