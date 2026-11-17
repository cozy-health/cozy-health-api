<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PlanCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_reseeding_retires_legacy_offers_without_repricing_historical_rows(): void
    {
        $legacy = ['audience' => 'mobile', 'country_code' => null, 'tier' => null,
            'currency' => 'NGN', 'duration' => 'monthly', 'duration_days' => 30,
            'features' => '[]', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()];
        DB::table('subscription_packages')->insert([
            [...$legacy, 'slug' => 'free', 'package_name' => 'Free', 'price' => 0],
            [...$legacy, 'slug' => 'premium', 'package_name' => 'Old Premium', 'price' => 5000],
        ]);
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);
        $this->assertDatabaseHas('subscription_packages', ['slug' => 'free', 'is_active' => false, 'price' => 0]);
        $this->assertDatabaseHas('subscription_packages', ['slug' => 'premium', 'is_active' => false, 'price' => 5000]);
        $active = DB::table('subscription_packages')->where('audience', 'mobile')->where('is_active', true)->get();
        $this->assertEqualsCanonicalizing(['standard', 'student', 'premium'], $active->pluck('tier')->all());
        foreach ($active as $row) {
            $benefits = json_decode($row->features, true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame(in_array($row->tier, ['student', 'premium'], true), in_array('Trends and insights dashboard', $benefits, true));
        }
        $this->assertDatabaseHas('subscription_packages', ['slug' => 'mobile-ng-student', 'price' => 5000, 'currency' => 'NGN']);
        $this->assertDatabaseHas('subscription_packages', ['slug' => 'mobile-ng-standard', 'price' => 6500, 'currency' => 'NGN']);
        $this->assertDatabaseHas('subscription_packages', ['slug' => 'mobile-ng-premium', 'price' => 10000, 'currency' => 'NGN']);
    }
}
