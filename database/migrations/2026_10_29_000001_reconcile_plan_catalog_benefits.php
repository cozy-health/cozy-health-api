<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            // Do not delete packages referenced by historical purchases or reprice them.
            DB::table('subscription_packages')->where('audience', 'mobile')
                ->where(fn ($query) => $query->where('tier', 'free')->orWhereIn('slug', ['free', 'premium']))
                ->update(['is_active' => false, 'updated_at' => now()]);
            $benefits = [
        'standard' => ['Written journals', 'Mood tracking and streaks', 'Read-only community access', 'Emergency support'],
        'student' => ['Written and voice journals', 'Mood tracking and streaks', 'Provider connection and chat', 'Explicit journal sharing', 'Assessments and challenges', 'Trends and insights dashboard', 'Full community participation', 'Emergency support'],
        'premium' => ['Written and voice journals', 'Mood tracking and streaks', 'Provider connection and chat', 'Explicit journal sharing', 'Assessments and challenges', 'Trends and insights dashboard', 'Full community participation', 'Emergency support'],
            ];
            foreach ($benefits as $tier => $features) {
                DB::table('subscription_packages')->where('audience', 'mobile')->where('tier', $tier)
                    ->update(['features' => json_encode($features, JSON_THROW_ON_ERROR), 'updated_at' => now()]);
            }
        });
    }

    public function down(): void
    {
        // Retired offers remain retired. Rollback must not reprice purchases or grant access.
    }
};
