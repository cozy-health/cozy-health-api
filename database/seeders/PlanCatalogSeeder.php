<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PlanCatalogSeeder extends Seeder
{
    public function run(): void
    {
        // Historical purchase/package rows keep their IDs and monetary amounts.
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
    }
}
