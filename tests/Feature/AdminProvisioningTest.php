<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminProvisioningTest extends TestCase
{
    use RefreshDatabase;
    public function test_new_account_requires_explicit_creation_and_valid_name_email(): void
    {
        $this->artisan('user:make-admin', ['email' => 'owner@example.test'])->assertFailed();
        $this->artisan('user:make-admin', ['email' => 'owner@example.test', '--create' => true])->assertFailed();
        $this->artisan('user:make-admin', ['email' => 'invalid', '--create' => true, '--name' => 'Owner'])->assertFailed();
        $this->assertDatabaseCount('users', 0);
    }
    public function test_new_account_has_admin_role_and_no_password_in_output(): void
    {
        $this->artisan('user:make-admin', ['email' => 'OWNER@example.test', '--create' => true, '--name' => 'Cordelia'])
            ->expectsOutput('Admin account provisioned. Existing passwords were preserved; new accounts need private password setup.')->assertSuccessful();
        $user = User::where('email', 'owner@example.test')->firstOrFail();
        $this->assertSame('admin', $user->role); $this->assertTrue($user->is_active);
        $this->assertNotEmpty($user->password); $this->assertNotSame('password', $user->password);
    }
    public function test_existing_account_promotion_is_idempotent_and_preserves_password(): void
    {
        $user = User::factory()->create(); $hash = $user->password;
        for ($i = 0; $i < 2; $i++) $this->artisan('user:make-admin', ['email' => $user->email, '--create' => true, '--name' => 'Ignored'])->assertSuccessful();
        $this->assertSame($hash, $user->fresh()->password); $this->assertSame('admin', $user->fresh()->role);
        $this->assertDatabaseCount('users', 1);
    }
    public function test_disabled_demo_and_deleted_accounts_are_never_promoted_or_restored(): void
    {
        foreach ([['is_active' => false], ['is_demo' => true], []] as $i => $attributes) {
            $user = User::factory()->create($attributes); if ($i === 2) $user->delete();
            $this->artisan('user:make-admin', ['email' => $user->email, '--create' => true, '--name' => 'Owner'])->assertFailed();
            $this->assertSame('user', User::withTrashed()->find($user->id)->role);
        }
    }
    public function test_password_setup_is_admin_only(): void
    {
        $user = User::factory()->create();
        $this->artisan('user:admin-password', ['email' => $user->email])->assertFailed();
    }
    public function test_password_setup_requires_matching_strong_values_and_preserves_existing_hash_on_failure(): void
    {
        $user = User::factory()->create(); $user->forceFill(['role' => 'admin'])->save(); $hash = $user->password;
        $this->artisan('user:admin-password', ['email' => $user->email])->expectsQuestion('New password', 'short')->expectsQuestion('Confirm password', 'short')->assertFailed();
        $this->artisan('user:admin-password', ['email' => $user->email])->expectsQuestion('New password', 'test-password-one')->expectsQuestion('Confirm password', 'test-password-two')->assertFailed();
        $this->assertSame($hash, $user->fresh()->password);
    }
    public function test_hidden_password_setup_hashes_secret_and_revokes_all_tokens(): void
    {
        $user = User::factory()->create(); $user->forceFill(['role' => 'admin'])->save();
        $user->createToken('cozy-admin-dashboard', ['admin:metrics'], now()->addMinutes(30));
        $this->artisan('user:admin-password', ['email' => $user->email])->expectsQuestion('New password', 'fixture-private-password')->expectsQuestion('Confirm password', 'fixture-private-password')->assertSuccessful();
        $this->assertTrue(Hash::check('fixture-private-password', $user->fresh()->password));
        $this->assertSame(0, $user->tokens()->count());
    }
}
