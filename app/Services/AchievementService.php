<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class AchievementService
{
    public function retain(User $user): void
    {
        $progress = app(StreakService::class)->snapshot($user);
        $awards = $progress['milestones'];
        $dates = $progress['completed_dates'];
        if ($dates) $awards['first_mood'] = $dates[0];
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
