<?php

namespace Tests\Feature;

use App\Models\AudioAttachment;
use App\Models\Provider;
use App\Models\ProviderReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class AccountFileDeletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_account_deletion_queues_private_audio_and_report_cleanup_without_touching_other_accounts(): void
    {
        Mail::fake();
        Storage::fake('local');
        $user = User::factory()->create();
        $other = User::factory()->create();
        $provider = Provider::create(['name' => 'Dr. Test', 'email' => 'provider@example.test',
            'practice' => 'Test Practice', 'is_active' => true, 'is_verified' => true]);
        $audio = [];
        foreach ([$user, $other] as $owner) {
            $path = 'audio/'.$owner->id.'/'.Str::uuid().'.m4a';
            Storage::disk('local')->put($path, 'private-recording');
            $audio[$owner->id] = AudioAttachment::create([
                'id' => (string) Str::uuid(), 'owner_type' => 'patient', 'owner_id' => $owner->id,
                'client_id' => (string) Str::uuid(), 'purpose' => 'journal', 'disk' => 'local',
                'storage_key' => $path, 'media_type' => 'audio/mp4', 'byte_size' => 17, 'duration' => 1,
            ]);
        }
        $reports = [];
        foreach ([$user, $other] as $owner) {
            $path = 'provider-reports/'.$provider->id.'/'.Str::uuid().'.pdf';
            Storage::disk('local')->put($path, '%PDF-private');
            $reports[$owner->id] = ProviderReport::create([
                'provider_id' => $provider->id, 'user_id' => $owner->id, 'type' => 'summary',
                'period_start' => today()->subWeek(), 'period_end' => today(),
                'file_path' => $path, 'generated_at' => now(),
            ]);
        }

        $this->withToken($user->createToken('device')->plainTextToken)
            ->deleteJson('/api/v1/account')->assertOk();
        $this->assertDatabaseMissing('audio_attachments', ['id' => $audio[$user->id]->id]);
        $this->assertDatabaseMissing('provider_reports', ['id' => $reports[$user->id]->id]);
        foreach ([$audio[$user->id]->storage_key, $reports[$user->id]->file_path] as $path) {
            $this->assertDatabaseHas('avatar_photo_deletions', ['disk' => 'local', 'object_key' => $path]);
        }
        $this->artisan('avatars:cleanup')->assertSuccessful();
        Storage::disk('local')->assertMissing($audio[$user->id]->storage_key);
        Storage::disk('local')->assertMissing($reports[$user->id]->file_path);
        Storage::disk('local')->assertExists($audio[$other->id]->storage_key);
        Storage::disk('local')->assertExists($reports[$other->id]->file_path);
        $this->assertDatabaseHas('audio_attachments', ['id' => $audio[$other->id]->id]);
        $this->assertDatabaseHas('provider_reports', ['id' => $reports[$other->id]->id]);
    }

    public function test_a_failed_account_deletion_rolls_back_file_cleanup_work(): void
    {
        $user = User::factory()->create();
        $audio = AudioAttachment::create([
            'id' => (string) Str::uuid(), 'owner_type' => 'patient', 'owner_id' => $user->id,
            'client_id' => (string) Str::uuid(), 'purpose' => 'journal', 'disk' => 'local',
            'storage_key' => 'audio/retained.m4a', 'media_type' => 'audio/mp4', 'byte_size' => 17, 'duration' => 1,
        ]);
        DB::beginTransaction();
        $user->forceDelete();
        DB::rollBack();
        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $this->assertDatabaseHas('audio_attachments', ['id' => $audio->id]);
        $this->assertDatabaseMissing('avatar_photo_deletions', ['object_key' => 'audio/retained.m4a']);
    }
}
