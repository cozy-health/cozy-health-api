<?php

namespace Tests\Feature;

use App\Jobs\ProcessPrivacyDeletion;
use App\Models\AudioAttachment;
use App\Models\Provider;
use App\Models\ProviderClientLink;
use App\Models\ProviderMessage;
use App\Models\SupportReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class CustomerPrivacyTest extends TestCase
{
    use RefreshDatabase;

    private function customer(): User
    {
        return User::factory()->create(['password' => Hash::make('Fixture7!Strong')]);
    }

    private function login(User $user, array $abilities = ['*']): void
    {
        app('auth')->forgetGuards();
        $this->withToken($user->createToken($user->role === 'admin' ? 'cozy-admin-management-v1' : 'device', $abilities, now()->addMinutes(30))->plainTextToken);
    }

    private function submit(User $user, string $kind = 'export', array $changes = []): string
    {
        $this->login($user);

        return $this->postJson('/api/v1/privacy-requests', ['kind' => $kind, 'password' => 'Fixture7!Strong', 'request_id' => (string) Str::uuid(), 'confirmed' => true, 'changes' => $changes])->assertCreated()->json('data.id');
    }

    private function reviewer(): User
    {
        $admin = User::factory()->create(['role' => 'admin']);
        config(['operations.privacy_reviewers' => [$admin->id]]);
        $this->login($admin, ['admin:privacy', 'admin:metrics']);

        return $admin;
    }

    private function approve(string $id, int $version = 1)
    {
        return $this->postJson('/api/v1/admin/privacy-requests/'.$id.'/process', ['decision' => 'approve', 'outcome' => 'Verified customer request.', 'expected_version' => $version]);
    }

    public function test_requests_require_identity_confirmation_and_are_idempotent(): void
    {
        $user = $this->customer();
        $this->login($user);
        $data = ['kind' => 'export', 'password' => 'wrong', 'confirmed' => true, 'request_id' => (string) Str::uuid()];
        $this->postJson('/api/v1/privacy-requests', $data)->assertUnprocessable();
        $data['password'] = 'Fixture7!Strong';
        $first = $this->postJson('/api/v1/privacy-requests', $data)->assertCreated();
        $first->assertJsonMissingPath('data.identity_fingerprint')->assertJsonMissingPath('data.verified_at');
        $this->postJson('/api/v1/privacy-requests', $data)->assertCreated()->assertJsonPath('data.id', $first->json('data.id'));
        $this->postJson('/api/v1/privacy-requests', [...$data, 'kind' => 'deletion'])->assertConflict();
        $this->postJson('/api/v1/privacy-requests', [...$data, 'confirmed' => false])->assertUnprocessable();
        $this->assertDatabaseCount('privacy_requests', 1);
        $user = $this->customer();
        $user->forceFill(['email_verified_at' => null])->save();
        $this->login($user);
        $this->postJson('/api/v1/privacy-requests', [...$data, 'request_id' => (string) Str::uuid()])->assertUnprocessable();
    }

    public function test_support_and_moderation_do_not_grant_privacy_access_and_assignment_revocation_is_immediate(): void
    {
        $id = $this->submit($this->customer());
        $admin = $this->reviewer();
        $this->login($admin, ['admin:support', 'admin:moderation', 'admin:users']);
        $this->getJson('/api/v1/admin/privacy-requests/'.$id)->assertForbidden();
        $this->login($admin, ['admin:privacy']);
        $this->getJson('/api/v1/admin/privacy-requests/'.$id)->assertOk();
        config(['operations.privacy_reviewers' => []]);
        $this->getJson('/api/v1/admin/privacy-requests/'.$id)->assertForbidden();
    }

    public function test_corrections_use_existing_profile_validation_and_cannot_modify_identity_or_clinical_fields(): void
    {
        $user = $this->customer();
        $this->login($user);
        $this->postJson('/api/v1/privacy-requests', ['kind' => 'correction', 'password' => 'Fixture7!Strong', 'confirmed' => true,
            'request_id' => (string) Str::uuid(), 'changes' => ['role' => 'admin']])->assertUnprocessable();
        $profileId = $this->getJson('/api/v1/user/profile')->assertOk()->json('data.id');
        $id = $this->submit($user, 'correction', ['bio' => 'Corrected profile', 'timezone' => 'Africa/Lagos']);
        $this->assertStringNotContainsString('Corrected profile', DB::table('privacy_requests')->where('id', $id)->value('changes'));
        $this->reviewer();
        $this->approve($id)->assertOk()->assertJsonPath('data.status', 'completed');
        $this->assertDatabaseHas('user_profiles', ['id' => $profileId, 'user_id' => $user->id, 'bio' => 'Corrected profile']);
        $this->assertDatabaseCount('user_profiles', 1);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'timezone' => 'Africa/Lagos', 'role' => 'user']);
        $this->getJson('/api/v1/admin/auth/session')->assertOk()->assertJsonPath('role', 'admin');
        $this->approve($id)->assertConflict();
    }

    public function test_changed_identity_invalidates_old_request_and_customer_cannot_read_another_request(): void
    {
        $user = $this->customer();
        $id = $this->submit($user);
        $this->login($this->customer());
        $this->postJson('/api/v1/privacy-requests/'.$id.'/download', ['password' => 'Fixture7!Strong'])->assertNotFound();
        $this->getJson('/api/v1/privacy-requests')->assertOk()->assertJsonCount(0, 'data.data');
        $user->update(['password' => 'Replacement7!Strong']);
        $this->reviewer();
        $this->approve($id)->assertConflict();
    }

    public function test_export_requires_approval_and_owner_reverification_and_excludes_staff_and_foreign_data(): void
    {
        Storage::fake('local');
        $user = $this->customer();
        $other = $this->customer();
        SupportReport::create(['user_id' => $user->id, 'subject' => 'Own question', 'message' => 'Own text', 'admin_notes' => 'NEVER_EXPORT_STAFF_NOTE']);
        SupportReport::create(['user_id' => $other->id, 'subject' => 'NEVER_EXPORT_FOREIGN', 'message' => 'Foreign text']);
        $provider = Provider::create(['name' => 'Fixture provider', 'email' => 'export-provider@example.test', 'practice' => 'Fixture', 'is_active' => true, 'is_verified' => true]);
        $link = ProviderClientLink::create(['provider_id' => $provider->id, 'user_id' => $user->id, 'provider_email' => $provider->email]);
        ProviderMessage::create(['provider_client_link_id' => $link->id, 'sender' => 'patient', 'client_id' => (string) Str::uuid(), 'content' => 'Owned conversation message']);
        $audioId = (string) Str::uuid();
        Storage::disk('local')->put('audio/own.m4a', 'owned-audio');
        AudioAttachment::create(['id' => $audioId, 'owner_type' => 'patient', 'owner_id' => $user->id, 'client_id' => (string) Str::uuid(),
            'purpose' => 'journal', 'disk' => 'local', 'storage_key' => 'audio/own.m4a', 'media_type' => 'audio/mp4', 'byte_size' => 11, 'duration' => 1]);
        $id = $this->submit($user);
        $this->postJson('/api/v1/privacy-requests/'.$id.'/download', ['password' => 'Fixture7!Strong'])->assertConflict();
        $this->reviewer();
        $this->approve($id)->assertOk();
        $this->login($user);
        $this->postJson('/api/v1/privacy-requests/'.$id.'/download', ['password' => 'wrong'])->assertUnprocessable();
        $response = $this->postJson('/api/v1/privacy-requests/'.$id.'/download', ['password' => 'Fixture7!Strong'])->assertOk()->assertHeader('Content-Type', 'application/zip');
        $path = tempnam(sys_get_temp_dir(), 'cozy-test-export-');
        try {
            file_put_contents($path, $response->streamedContent());
            $zip = new \ZipArchive;
            $this->assertTrue($zip->open($path));
            $records = $zip->getFromName('data/support_reports.json');
            $this->assertStringContainsString('Own text', $records);
            $this->assertStringNotContainsString('NEVER_EXPORT_STAFF_NOTE', $records);
            $this->assertStringNotContainsString('NEVER_EXPORT_FOREIGN', $records);
            $this->assertSame('owned-audio', $zip->getFromName('files/audio/'.$audioId));
            $this->assertStringContainsString('Owned conversation message', $zip->getFromName('data/provider_messages.json'));
            $account = $zip->getFromName('account.json');
            $this->assertStringNotContainsString('password', $account);
            $this->assertStringNotContainsString('apple_refresh_token', $account);
            $zip->close();
        } finally {
            @unlink($path);
        }
    }

    public function test_customer_deletion_reuses_existing_account_and_file_cleanup_without_removing_other_accounts(): void
    {
        Mail::fake();
        $user = $this->customer();
        $other = $this->customer();
        $id = $this->submit($user, 'deletion');
        $this->getJson('/api/v1/user/profile')->assertOk();
        DB::table('user_profiles')->where('user_id', $user->id)->update(['avatar_object_key' => 'avatars/privacy-deletion-fixture.jpg']);
        $reviewer = $this->reviewer();
        $this->approve($id)->assertOk()->assertJsonPath('data.status', 'processing');
        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $this->assertDatabaseCount('jobs', 1);
        app()->call([new ProcessPrivacyDeletion($id, 2, $reviewer->id), 'handle']);
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertDatabaseMissing('privacy_requests', ['id' => $id]);
        $this->assertDatabaseHas('users', ['id' => $other->id]);
        $this->assertDatabaseHas('admin_audit_logs', ['resource_id' => $id, 'action' => 'delete-account']);
        $this->assertDatabaseHas('avatar_photo_deletions', ['object_key' => 'avatars/privacy-deletion-fixture.jpg']);
        $this->getJson('/api/v1/admin/auth/session')->assertOk()->assertJsonPath('role', 'admin');
    }

    public function test_holds_prevent_processing_without_a_fresh_version_and_are_visible_to_customer(): void
    {
        $user = $this->customer();
        $id = $this->submit($user);
        $this->reviewer();
        $this->postJson('/api/v1/admin/privacy-requests/'.$id.'/process', ['decision' => 'hold', 'outcome' => 'Awaiting request clarification.', 'expected_version' => 1])->assertOk()->assertJsonPath('data.status', 'held');
        $this->approve($id)->assertConflict();
        $this->login($user);
        $this->getJson('/api/v1/privacy-requests')->assertOk()->assertJsonPath('data.data.0.outcome', 'Awaiting request clarification.');
    }

    public function test_deletion_worker_failure_is_retryable_and_old_jobs_cannot_repeat_a_new_decision(): void
    {
        $user = $this->customer();
        $id = $this->submit($user, 'deletion');
        $reviewer = $this->reviewer();
        $this->approve($id)->assertOk()->assertJsonPath('data.status', 'processing');
        $job = new ProcessPrivacyDeletion($id, 2, $reviewer->id);
        $job->failed(new \RuntimeException('Synthetic transport failure'));
        $this->assertDatabaseHas('privacy_requests', ['id' => $id, 'status' => 'failed', 'version' => 3]);
        $this->approve($id, 3)->assertOk()->assertJsonPath('data.status', 'processing')->assertJsonPath('data.version', 4);
        app()->call([$job, 'handle']);
        $this->assertDatabaseHas('users', ['id' => $user->id]);
        app()->call([new ProcessPrivacyDeletion($id, 4, $reviewer->id), 'handle']);
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }

    public function test_correction_revalidates_new_conflicts_and_restores_admin_context_after_failure(): void
    {
        $user = $this->customer();
        $id = $this->submit($user, 'correction', ['username' => 'claimedname']);
        $other = $this->customer();
        $this->login($other);
        $this->patchJson('/api/v1/user/profile', ['username' => 'claimedname'])->assertOk();
        $this->reviewer();
        $this->approve($id)->assertUnprocessable();
        $this->assertDatabaseHas('privacy_requests', ['id' => $id, 'status' => 'pending', 'version' => 1]);
        $this->getJson('/api/v1/admin/auth/session')->assertOk()->assertJsonPath('role', 'admin');
    }
}
