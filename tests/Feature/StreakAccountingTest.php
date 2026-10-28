<?php

use App\Services\StreakAccounting;
use Carbon\CarbonImmutable;

function streakDates(int $count): array
{
    return array_map(fn ($i) => CarbonImmutable::parse('2026-10-01')->addDays($i)->toDateString(), range(0, $count - 1));
}

it('protects seven without counting a missed day and resumes at eight', function () {
    $service = new StreakAccounting;
    $protected = $service->calculate(streakDates(7), '2026-10-09', '2026-10-01');
    expect($protected['current_days'])->toBe(7)->and($protected['rest_days'])->toBe(0)
        ->and($protected['protected_dates'])->toBe(['2026-10-08']);
    $resumed = $service->calculate([...streakDates(7), '2026-10-09'], '2026-10-09', '2026-10-01');
    expect($resumed['current_days'])->toBe(8)->and($resumed['earning_progress'])->toBe(1);
});

it('caps the bank and does not spend today before midnight', function () {
    $result = (new StreakAccounting)->calculate(streakDates(21), '2026-10-22', '2026-10-01');
    expect($result['rest_days'])->toBe(2)->and($result['current_days'])->toBe(21)
        ->and($result['protected_dates'])->toBe([]);
});

it('replays late offline completion without duplicate spending or credit', function () {
    $service = new StreakAccounting;
    $dates = [...streakDates(9), '2026-10-08'];
    $first = $service->calculate($dates, '2026-10-09', '2026-10-01');
    expect($first['current_days'])->toBe(9)->and($first['rest_days'])->toBe(1)
        ->and($first['protected_dates'])->toBe([])
        ->and($service->calculate($dates, '2026-10-09', '2026-10-01'))->toBe($first);
});

it('does not award retroactive credits and resets an unprotected streak', function () {
    $result = (new StreakAccounting)->calculate(streakDates(14), '2026-10-17', '2026-10-15');
    expect($result['current_days'])->toBe(0)->and($result['best_days'])->toBe(14)
        ->and($result['rest_days'])->toBe(0);
});
