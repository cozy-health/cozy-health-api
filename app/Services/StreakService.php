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
                ->whereNotExists(function ($query) {
                    $query->selectRaw('1')->from('streak_completions')
                        ->whereColumn('streak_completions.user_id', 'mood_entries.user_id')
                        ->whereColumn('streak_completions.source_id', 'mood_entries.id');
                })
                ->select(['id', 'client_created_at', 'created_at'])->cursor();
            $pending = [];
            foreach ($moods as $mood) {
                $date = CarbonImmutable::parse($mood->client_created_at ?? $mood->created_at, 'UTC')
                    ->setTimezone($user->timezone ?? 'UTC')->toDateString();
                if ($date <= $today) {
                    $pending[] = [
                        'user_id' => $user->id, 'source_id' => $mood->id, 'completed_date' => $date,
                    ];
                    if (count($pending) === 250) {
                        DB::table('streak_completions')->insertOrIgnore($pending);
                        $pending = [];
                    }
                }
            }
            if ($pending) {
                DB::table('streak_completions')->insertOrIgnore($pending);
            }
            $sources = DB::table('streak_completions')->where('user_id', $user->id)
                ->pluck('completed_date', 'source_id')->all();
            $dates = array_values(array_unique(array_values($sources)));

            return app(StreakAccounting::class)->calculate($dates, $today, $account->earning_starts)
                + ['timezone' => $user->timezone ?? 'UTC', 'local_date' => $today,
                    'account_id' => (string) $user->id, 'accounting_version' => 1,
                    'earning_starts' => $account->earning_starts, 'as_of' => now()->utc()->toISOString(),
                    'credited_sources' => (object) $sources];
        });
    }
}
