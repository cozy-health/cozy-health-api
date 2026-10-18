<?php

namespace App\Services;

use App\Models\Affirmation;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AffirmationAssignmentService
{
    public function recordRead(User $user, array $data): void
    {
        $validated = \Illuminate\Support\Facades\Validator::make($data, [
            'read_at' => ['required', 'date', 'before_or_equal:now'],
        ])->validate();
        $read = Carbon::parse($validated['read_at'])->utc();
        // A delayed offline acknowledgement must not remain permanently pending.
        if ($read->lt(now()->subDays(7))) return;
        DB::table('user_affirmation_reads')->insertOrIgnore([
            'user_id' => $user->id,
            'local_date' => $read->copy()->setTimezone($user->timezone ?? 'UTC')->toDateString(),
            'read_at' => $read,
        ]);
    }

    public function current(User $user): ?array
    {
        return DB::transaction(function () use ($user) {
            // All devices serialize on an existing row, including the first assignment.
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $now = now()->utc()->startOfSecond();
            $active = Affirmation::where('is_active', true)->orderBy('id')->get(['id', 'body']);
            if ($active->isEmpty()) {
                return null;
            }

            $assignment = DB::table('user_affirmation_assignments')->where('user_id', $user->id)->first();
            $selected = $assignment ? $active->firstWhere('id', $assignment->affirmation_id) : null;
            if (! $selected || Carbon::parse($assignment->expires_at, 'UTC')->lte($now)) {
                $selected = $assignment
                    ? ($active->first(fn ($entry) => $entry->id > $assignment->affirmation_id) ?? $active->first())
                    : $active[crc32((string) $user->id) % $active->count()];
                $values = [
                    'affirmation_id' => $selected->id,
                    'assigned_at' => $now->toDateTimeString(),
                    'expires_at' => $now->copy()->addHours(24)->toDateTimeString(),
                ];
                DB::table('user_affirmation_assignments')->updateOrInsert(['user_id' => $user->id], $values);
                $assignment = (object) $values;
            }

            return [
                'id' => $selected->id,
                'body' => $selected->body,
                // Compatibility for old clients; selection is no longer based on this date.
                'date' => $user->localToday()->toDateString(),
                'assigned_at' => Carbon::parse($assignment->assigned_at, 'UTC')->toIso8601String(),
                'expires_at' => Carbon::parse($assignment->expires_at, 'UTC')->toIso8601String(),
            ];
        }, 3);
    }
}
