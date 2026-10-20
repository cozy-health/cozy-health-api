<?php
namespace Tests\Feature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Schema};
use Illuminate\Support\Str;
use Tests\TestCase;

class Phase19MigrationTest extends TestCase
{
    use RefreshDatabase;
    private function migration() { return require database_path('migrations/2026_10_13_000002_add_canonical_username_key.php'); }
    private function profile(string $username): string
    {
        $owner = User::factory()->create(); $id = (string) Str::uuid();
        DB::table('user_profiles')->insert(['id' => $id, 'user_id' => $owner->id, 'name' => $owner->name, 'email' => $owner->email, 'username' => $username, 'created_at' => now(), 'updated_at' => now()]);
        return $id;
    }
    public function test_populated_upgrade_and_rollback_preserve_legacy_handles(): void
    {
        $migration = $this->migration(); $migration->down();
        $id = $this->profile(' Legacy.Name ');
        $migration->up();
        $this->assertDatabaseHas('user_profiles', ['id' => $id, 'username' => ' Legacy.Name ', 'username_key' => 'legacy.name']);
        $migration->down();
        $this->assertFalse(Schema::hasColumn('user_profiles', 'username_key'));
        $this->assertDatabaseHas('user_profiles', ['id' => $id, 'username' => ' Legacy.Name ']);
        $migration->up();
    }
    public function test_collision_fails_before_schema_mutation(): void
    {
        $migration = $this->migration(); $migration->down();
        $first = $this->profile('Collision'); $second = $this->profile(' Collision');
        try { $migration->up(); $this->fail('Expected a normalized username conflict.'); }
        catch (\RuntimeException $error) { $this->assertStringContainsString('Normalized usernames conflict', $error->getMessage()); }
        $this->assertFalse(Schema::hasColumn('user_profiles', 'username_key'));
        DB::table('user_profiles')->where('id', $second)->update(['username' => 'Resolved']);
        $migration->up();
        $this->assertDatabaseHas('user_profiles', ['id' => $first, 'username_key' => 'collision']);
    }
}
