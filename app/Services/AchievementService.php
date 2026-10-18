<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class AchievementService
{
    public function retain(User $user): void
    {
        $awards = [];
        $days = [];
        $moods = DB::table('mood_entries')->where('user_id', $user->id)->where('is_deleted', false)
            ->whereNull('deleted_at')
            ->orderBy('client_created_at')->cursor();
        foreach ($moods as $mood) {
            $date = \Carbon\CarbonImmutable::parse($mood->client_created_at ?? $mood->created_at, 'UTC')
                ->setTimezone($user->timezone ?? 'UTC')->toDateString();
            $days[$date] = true;
        }
        $dates = array_keys($days); sort($dates);
        if ($dates) $awards['first_mood'] = $dates[0];
        $previous = null; $streak = 0;
        foreach ($dates as $date) {
            $streak = $previous && \Carbon\CarbonImmutable::parse($previous)->addDay()->toDateString() === $date ? $streak + 1 : 1;
            foreach ([3, 7, 30] as $target) if ($streak >= $target && ! isset($awards['streak_'.$target])) $awards['streak_'.$target] = $date;
            $previous = $date;
        }
        $journal = DB::table('journal_entries')->where('user_id', $user->id)->where('is_deleted', false)
            ->whereNull('deleted_at')->where('is_draft', false)
            ->orderBy('client_created_at')->first();
        if ($journal) $awards['first_journal'] = \Carbon\CarbonImmutable::parse($journal->client_created_at ?? $journal->created_at, 'UTC')
            ->setTimezone($user->timezone ?? 'UTC')->toDateString();
        foreach ($awards as $milestone => $date) DB::table('user_achievement_awards')->insertOrIgnore([
            'user_id' => $user->id, 'milestone' => $milestone, 'earned_date' => $date,
        ]);
    }
}
