<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Catalog only: never rewrite receipts, purchased amounts or subscriptions.
        DB::table('subscription_packages')->where('audience', 'mobile')->where('tier', 'free')->update(['is_active' => false, 'updated_at' => now()]);
        DB::table('subscription_packages')->insertOrIgnore(['package_name' => 'Standard', 'slug' => 'mobile-ng-standard',
            'audience' => 'mobile', 'country_code' => 'NG', 'tier' => 'standard', 'price' => 6500, 'currency' => 'NGN',
            'duration' => 'monthly', 'duration_days' => 30, 'annual_discount' => 20, 'currency_decimals' => 2,
            'features' => json_encode(['Written journals', 'Mood tracking', 'Read-only community', 'Emergency help']),
            'is_active' => true, 'sort_order' => 6500, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('subscription_packages')->where('audience', 'mobile')->whereIn('tier', ['student', 'premium'])->update([
            'features' => json_encode(['Written journals', 'Mood tracking', 'Voice', 'Provider chat and journal sharing', 'Assessments', 'Challenges', 'Community participation', 'Emergency help']), 'updated_at' => now()]);
    }
    public function down(): void
    {
        DB::table('subscription_packages')->where('slug', 'mobile-ng-standard')->delete();
        // Retired Free rows remain retired; rollback must not grant access.
    }
};
