<?php

namespace App\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class StreakService
{
    public function snapshot(User $user): array
    {
        return DB::transaction(function () use ($user) {
            DB::table('users')->where('id', $user->id)->lockForUpdate()->first();
            $today = $user->localToday()->toDateString();
            DB::table('streak_accounts')->insertOrIgnore([
                'user_id' => $user->id, 'earning_starts' => $today,
            ]);
            $account = DB::table('streak_accounts')->where('user_id', $user->id)->first();
            // Minimal durable history: no mood content; source IDs cannot earn twice.
            $moods = DB::table('mood_entries')->where('user_id', $user->id)
                ->where('is_deleted', false)->whereNull('deleted_at')
                ->select(['id', 'client_created_at', 'created_at'])->cursor();
            foreach ($moods as $mood) {
                $date = CarbonImmutable::parse($mood->client_created_at ?? $mood->created_at, 'UTC')
                    ->setTimezone($user->timezone ?? 'UTC')->toDateString();
                if ($date <= $today) {
                    DB::table('streak_completions')->insertOrIgnore([
                        'user_id' => $user->id, 'source_id' => $mood->id, 'completed_date' => $date,
                    ]);
                }
            }
            $dates = DB::table('streak_completions')->where('user_id', $user->id)
                ->distinct()->pluck('completed_date')->all();

            return app(StreakAccounting::class)->calculate($dates, $today, $account->earning_starts)
                + ['timezone' => $user->timezone ?? 'UTC', 'local_date' => $today];
        });
    }
}
