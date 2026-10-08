<?php

namespace Tests\Feature\Provider;

use App\Models\Provider;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProviderLinkMigrationTest extends TestCase
{
    use DatabaseMigrations;

    private function migration(): object
    {
        return require database_path('migrations/2026_10_09_000002_consolidate_provider_client_links.php');
    }

    public function test_copy_preserves_verified_pending_and_email_only_links_and_rollback(): void
    {
        $migration = $this->migration();
        $migration->down();
        $provider = Provider::create(['name' => 'Migration Test', 'email' => 'migration@example.test']);
        $existingUser = User::factory()->create();
        $existingId = DB::table('provider_client_links')->insertGetId([
            'provider_id' => $provider->id, 'user_id' => $existingUser->id,
            'linked_at' => '2026-09-01 10:00:00', 'consent_moods' => true,
        ]);
        $hash = password_hash('123456', PASSWORD_BCRYPT);
        foreach ([true, false, false] as $index => $verified) {
            $user = User::factory()->create();
            DB::table('provider_connections')->insert([
                'user_id' => $user->id, 'provider_id' => $index === 2 ? null : $provider->id,
                'provider_email' => $index === 2 ? 'Invite@Example.test' : $provider->email,
                'provider_name' => 'Stored Name', 'provider_phone' => '+234123456789',
                'verified_at' => $verified ? '2026-09-02 11:00:00' : null,
                'verification_code' => $verified ? null : $hash,
                'verification_expires_at' => $verified ? null : '2026-10-10 12:00:00',
                'created_at' => '2026-09-01 10:00:00', 'updated_at' => '2026-09-02 11:00:00',
            ]);
        }
        $originals = DB::table('provider_connections')->orderBy('id')->get();
        $migration->up();
        $this->assertSame(3, DB::table('provider_connections')->count());
        $this->assertSame(3, DB::table('provider_client_links')->whereNotNull('legacy_connection_id')->count());
        foreach ($originals as $old) {
            $new = DB::table('provider_client_links')->where('legacy_connection_id', $old->id)->first();
            $this->assertSame($old->user_id, $new->user_id);
            $this->assertSame($old->provider_id, $new->provider_id);
            $this->assertSame(strtolower($old->provider_email), $new->provider_email);
            $this->assertSame($old->verified_at, $new->verified_at);
            $this->assertSame($old->verified_at ?? $old->created_at, $new->linked_at);
            $this->assertSame($old->verification_code, $new->verification_code);
            $this->assertSame($old->verification_expires_at, $new->verification_expires_at);
            $this->assertSame($old->provider_name, $new->provider_name);
            $this->assertSame($old->provider_phone, $new->provider_phone);
            foreach (['moods', 'journals', 'quizzes', 'notes'] as $type) {
                $this->assertFalse((bool) $new->{'consent_'.$type});
            }
        }
        $this->assertTrue((bool) DB::table('provider_client_links')->where('id', $existingId)->value('consent_moods'));
        $migration->down();
        $this->assertSame(1, DB::table('provider_client_links')->count());
        $this->assertEquals($originals, DB::table('provider_connections')->orderBy('id')->get());
        $migration->up();
        $this->assertSame(4, DB::table('provider_client_links')->count());
        // DatabaseMigrations also rolls back the earlier hash-width migration at teardown.
        // Its intentional guard rejects live pending hashes; remove only our fixture after checks.
        DB::table('provider_connections')->delete();
    }

    public function test_conflicting_link_stops_before_schema_or_data_changes(): void
    {
        $migration = $this->migration();
        $migration->down();
        $provider = Provider::create(['name' => 'Conflict Test', 'email' => 'conflict@example.test']);
        $user = User::factory()->create();
        DB::table('provider_client_links')->insert(['provider_id' => $provider->id, 'user_id' => $user->id, 'linked_at' => now()]);
        DB::table('provider_connections')->insert(['provider_id' => $provider->id, 'user_id' => $user->id,
            'provider_email' => $provider->email, 'created_at' => now(), 'updated_at' => now()]);
        try {
            $migration->up();
            $this->fail('Conflicting links must stop the migration.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Conflicting legacy', $exception->getMessage());
            $this->assertFalse(Schema::hasColumn('provider_client_links', 'provider_email'));
            $this->assertSame(1, DB::table('provider_connections')->count());
            $this->assertSame(1, DB::table('provider_client_links')->count());
        }
        DB::table('provider_connections')->delete();
        $migration->up();
    }

    public function test_invalid_legacy_email_stops_before_schema_changes(): void
    {
        $migration = $this->migration();
        $migration->down();
        $user = User::factory()->create();
        DB::table('provider_connections')->insert(['user_id' => $user->id,
            'provider_email' => 'invalid', 'created_at' => now(), 'updated_at' => now()]);
        try {
            $migration->up();
            $this->fail('Invalid legacy data must stop migration.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Unsafe legacy', $exception->getMessage());
            $this->assertFalse(Schema::hasColumn('provider_client_links', 'provider_email'));
        }
        DB::table('provider_connections')->delete();
        // Simulate corrupt imported data that predates enforced foreign keys.
        foreach ([['user_id' => 999999, 'provider_id' => null], ['user_id' => $user->id, 'provider_id' => 999999]] as $badOwner) {
            Schema::disableForeignKeyConstraints();
            DB::table('provider_connections')->insert([...$badOwner, 'provider_email' => 'valid@example.test',
                'created_at' => now(), 'updated_at' => now()]);
            Schema::enableForeignKeyConstraints();
            try {
                $migration->up();
                $this->fail('Missing owner/provider must stop migration.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('Unsafe legacy', $exception->getMessage());
                $this->assertFalse(Schema::hasColumn('provider_client_links', 'provider_email'));
            }
            DB::table('provider_connections')->delete();
        }
        $migration->up();
    }
}
