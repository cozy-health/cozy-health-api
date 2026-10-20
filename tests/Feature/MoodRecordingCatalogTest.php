<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class MoodRecordingCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_reference_options_have_unique_keys_and_legacy_catalog_rows_survive(): void
    {
        $this->assertDatabaseHas('feelings', ['slug' => 'calm', 'is_active' => false]);
        $this->assertDatabaseHas('feeling_expressions', ['slug' => 'sad-frustrated']);
        $this->assertDatabaseHas('feeling_expressions', ['slug' => 'angry-frustrated']);
        $this->assertSame(48, DB::table('feeling_causes')->where('is_active', true)->count());
        $this->assertSame(6, DB::table('feeling_causes')->where('is_active', true)->distinct()->count('group_name'));
    }

    public function test_new_feeling_and_normalized_intensity_use_existing_entry_contract(): void
    {
        $user = User::factory()->create();
        $this->withToken($user->createToken('mobile')->plainTextToken);
        $id = (string) Str::uuid();
        $this->postJson('/api/v1/mood-entries', [
            'id' => $id, 'mood' => 'spectacular', 'expression_slug' => 'spectacular-elated',
            'intensity' => 10, 'triggers' => ['Deadlines', 'Workload'], 'coping_strategies' => ['Deep breathing'],
        ])->assertSuccessful();
        $this->assertDatabaseHas('mood_entries', ['id' => $id, 'intensity' => 10, 'expression_slug' => 'spectacular-elated']);
    }

    public function test_admin_can_change_a_cause_group_without_changing_its_key(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['role' => 'admin'])->save();
        $this->withToken($admin->createToken('cozy-admin-management-v1', ['admin:content'], now()->addMinutes(30))->plainTextToken);
        $row = DB::table('feeling_causes')->where('slug', 'work-deadlines')->first();
        $this->putJson('/api/v1/admin/content/triggers/'.$row->id, [
            'name' => $row->name, 'slug' => $row->slug, 'sort_order' => 0, 'is_active' => true, 'group_name' => 'School',
        ])->assertOk()->assertJsonPath('data.group_name', 'School');
    }
}
