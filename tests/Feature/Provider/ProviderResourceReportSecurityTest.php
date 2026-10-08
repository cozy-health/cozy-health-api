<?php

namespace Tests\Feature\Provider;

use App\Models\JournalEntry;
use App\Models\MoodEntry;
use App\Models\ProviderAssignment;
use App\Models\ProviderReport;
use App\Models\User;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Mockery;

class ProviderResourceReportSecurityTest extends ProviderDataTestCase
{
    public function test_report_excludes_other_patients_deleted_drafts_and_out_of_period_entries(): void
    {
        Storage::fake('local');
        $provider = $this->provider(['timezone' => 'Africa/Lagos']);
        $user = User::factory()->create();
        $other = User::factory()->create();
        $this->link($provider, $user, ['consent_moods' => true, 'consent_journals' => true]);
        $this->link($provider, $other, ['consent_moods' => true, 'consent_journals' => true]);
        $data = [];
        View::composer('provider.reports.monthly', function ($view) use (&$data) {
            $data = $view->getData();
        });
        // Local October 1 starts at 23:00 UTC on September 30 in Lagos.
        $valid = MoodEntry::create(['id' => (string) Str::uuid(), 'user_id' => $user->id, 'mood' => 'happy', 'intensity' => 4,
            'client_created_at' => '2026-09-30 23:30:00', 'triggers' => ['Work', 'Sleep', 'Family', 'Money', 'Health', 'Other']]);
        foreach ([['user_id' => $other->id], ['is_deleted' => true], ['client_created_at' => '2026-09-30 22:59:59'], ['client_created_at' => '2026-10-31 23:00:00']] as $overrides) {
            MoodEntry::create([...$valid->only(['user_id', 'mood', 'intensity', 'client_created_at']), 'id' => (string) Str::uuid(), ...$overrides]);
        }
        $deleted = MoodEntry::create([...$valid->only(['user_id', 'mood', 'intensity', 'client_created_at']), 'id' => (string) Str::uuid()]);
        $deleted->delete();
        foreach ([[], ['is_deleted' => true], ['is_draft' => true], ['user_id' => $other->id]] as $overrides) {
            JournalEntry::create(['id' => (string) Str::uuid(), 'user_id' => $user->id, 'type' => 'free', 'body' => 'private', 'client_created_at' => '2026-10-01 12:00:00', ...$overrides]);
        }
        $this->asProvider($provider)->postJson('/api/v1/provider/reports', ['user_id' => $user->id, 'from' => '2026-10-01', 'to' => '2026-10-31'])->assertCreated();
        $this->assertSame(1, $data['summary']['moods']['count']);
        $this->assertCount(5, $data['summary']['moods']['triggers']);
        $this->assertEquals(['count' => 1, 'types' => ['free' => 1]], $data['summary']['journals']);
    }

    public function test_signed_report_requires_provider_auth_and_original_link(): void
    {
        Storage::fake('local');
        $provider = $this->provider();
        $user = User::factory()->create();
        $link = $this->link($provider, $user);
        $id = $this->asProvider($provider)->postJson('/api/v1/provider/reports', ['user_id' => $user->id])->assertCreated()->json('report.id');
        $url = $this->getJson('/api/v1/provider/reports/'.$id.'/download')->assertOk()->json('download_url');
        $this->assertStringStartsWith('/api/v1/provider/reports/', $url);
        $this->get('https://api.example.test'.$url)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => ''])->get($url)->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->withToken($user->createToken('patient')->plainTextToken)->get($url)->assertUnauthorized();
        $link->update(['revoked_at' => now()]);
        // Reconnecting reuses the same database row but starts a new verification epoch.
        $link->update(['revoked_at' => null, 'verified_at' => now()->addMinute()]);
        $this->app['auth']->forgetGuards();
        $this->asProvider($provider)->get($url)->assertNotFound();
        $this->assertDatabaseCount('provider_access_logs', 3); // create, URL issuance, one authenticated HTTPS download
    }

    public function test_missing_pdf_and_legacy_report_without_snapshot_fail_closed(): void
    {
        Storage::fake('local');
        $provider = $this->provider();
        $user = User::factory()->create();
        $this->link($provider, $user);
        $id = $this->asProvider($provider)->postJson('/api/v1/provider/reports', ['user_id' => $user->id])->assertCreated()->json('report.id');
        $report = ProviderReport::findOrFail($id);
        $url = $this->getJson('/api/v1/provider/reports/'.$id.'/download')->assertOk()->json('download_url');
        Storage::disk('local')->delete($report->file_path);
        $this->getJson('/api/v1/provider/reports/'.$id.'/download')->assertNotFound();
        $this->get($url)->assertNotFound();
        $report->update(['consent_snapshot' => null]);
        $this->getJson('/api/v1/provider/reports/'.$id)->assertNotFound();
    }

    public function test_storage_failure_rolls_back_report_and_audit(): void
    {
        $provider = $this->provider();
        $user = User::factory()->create();
        $this->link($provider, $user);
        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('put')->once()->andReturn(false);
        $disk->shouldReceive('delete')->once()->andReturn(true);
        Storage::shouldReceive('disk')->with('local')->andReturn($disk);
        $this->asProvider($provider)->postJson('/api/v1/provider/reports', ['user_id' => $user->id])->assertStatus(500);
        $this->assertDatabaseCount('provider_reports', 0);
        $this->assertDatabaseCount('provider_access_logs', 0);
    }

    public function test_assignments_are_patient_scoped_and_unverified_links_are_hidden(): void
    {
        $provider = $this->provider();
        $patient = User::factory()->create();
        $other = User::factory()->create();
        $pending = $this->link($provider, $patient, ['verified_at' => null]);
        $this->link($provider, $other);
        $resource = $provider->resources()->create(['title' => 'Exercise', 'type' => 'exercise']);
        $foreign = ProviderAssignment::create(['provider_id' => $provider->id, 'user_id' => $other->id, 'resource_id' => $resource->id, 'assigned_at' => now()]);
        ProviderAssignment::create(['provider_id' => $provider->id, 'user_id' => $patient->id, 'resource_id' => $resource->id, 'assigned_at' => now()]);
        $this->asProvider($provider)->getJson('/api/v1/provider/clients/'.$other->id.'/resources')->assertOk()->assertJsonCount(1, 'data');
        $pending->update(['verified_at' => now()]);
        $this->deleteJson('/api/v1/provider/clients/'.$patient->id.'/resources/'.$foreign->id)->assertNotFound();
        $pending->update(['verified_at' => null]);
        $this->app['auth']->forgetGuards();
        $this->withToken($patient->createToken('patient')->plainTextToken)->getJson('/api/v1/me/resources')->assertOk()->assertJsonCount(0, 'data');
        $this->patchJson('/api/v1/me/resources/'.$foreign->id, ['status' => 'completed'])->assertNotFound();
    }
}
