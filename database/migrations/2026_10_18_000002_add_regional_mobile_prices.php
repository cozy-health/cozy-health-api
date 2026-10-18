<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('subscription_packages', function (Blueprint $table) {
            $table->decimal('price', 14, 3)->change();
            $table->string('audience')->default('mobile');
            $table->string('country_code', 2)->nullable();
            $table->string('tier')->nullable();
            $table->unsignedTinyInteger('annual_discount')->default(20);
            $table->unsignedTinyInteger('currency_decimals')->default(2);
            $table->unique(['audience', 'country_code', 'tier']);
        });
        foreach (['free' => 0, 'student' => 5000, 'premium' => 10000] as $tier => $price) {
            DB::table('subscription_packages')->insert([
                'package_name' => ucfirst($tier), 'slug' => 'mobile-ng-'.$tier,
                'audience' => 'mobile', 'country_code' => 'NG', 'tier' => $tier,
                'price' => $price, 'currency' => 'NGN', 'duration' => 'monthly', 'duration_days' => 30,
                'annual_discount' => 20, 'features' => json_encode($tier === 'free'
                    ? ['Mood tracking', 'Journaling']
                    : ['Mood tracking', 'Journaling', 'Detailed insights', 'Data export']),
                'is_active' => true, 'sort_order' => $price, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('subscription_packages')->whereIn('slug', ['mobile-ng-free', 'mobile-ng-student', 'mobile-ng-premium'])->delete();
        Schema::table('subscription_packages', function (Blueprint $table) {
            $table->dropUnique(['audience', 'country_code', 'tier']);
            $table->dropColumn(['audience', 'country_code', 'tier', 'annual_discount', 'currency_decimals']);
        });
    }
};
