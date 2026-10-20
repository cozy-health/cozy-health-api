<?php

namespace Tests\Feature\Provider;

use App\Models\ProviderAccessLog;
use App\Models\ProviderReport;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProviderRetentionTest extends ProviderDataTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        Storage::fake('local');
        config(['provider-retention.access_log_days' => 730,
            'provider-retention.approval_reference' => 'test-policy-v1',
            'provider-retention.global_hold' => false,
            'provider-retention.held_provider_ids' => '']);
    }

    private function log(array $attributes = []): ProviderAccessLog
    {
        return ProviderAccessLog::create([...['provider_id' => $this->provider()->id,
            'action' => 'view', 'resource_type' => 'mood', 'created_at' => now()->subDays(731)], ...$attributes]);
    }

    private function pdf(int $providerId, bool $old = true): string
    {
        $path = 'provider-reports/'.$providerId.'/'.Str::uuid().'.pdf';
        Storage::disk('local')->put($path, '%PDF-test');
        touch(Storage::disk('local')->path($path), ($old ? now()->subDays(2) : now())->timestamp);
        return $path;
    }

    public function test_default_run_previews_without_deleting(): void
    {
        $this->log();
        $path = $this->pdf($this->provider()->id);
        $this->artisan('provider:prune', ['--orphan-reports' => true])
            ->expectsOutput('Eligible (preview only): 1 routine audit entries; 1 orphan report PDFs.')->assertSuccessful();
        $this->assertDatabaseCount('provider_access_logs', 1);
        Storage::disk('local')->assertExists($path);
    }

    public function test_execution_requires_approval_and_valid_configuration(): void
    {
        $log = $this->log();
        config(['provider-retention.approval_reference' => '']);
        $this->artisan('provider:prune', ['--execute' => true])->assertFailed();
        config(['provider-retention.approval_reference' => 'test', 'provider-retention.access_log_days' => '0']);
        $this->artisan('provider:prune', ['--execute' => true])->assertFailed();
        config(['provider-retention.access_log_days' => '730days']);
        $this->artisan('provider:prune', ['--execute' => true])->assertFailed();
        config(['provider-retention.access_log_days' => 730, 'provider-retention.held_provider_ids' => '1,invalid']);
        $this->artisan('provider:prune', ['--execute' => true])->assertFailed();
        $this->assertDatabaseHas('provider_access_logs', ['id' => $log->id]);
    }

    public function test_cutoff_preserves_evidence_unknown_actions_and_recent_entries(): void
    {
        $old = $this->log();
        $boundary = $this->log(['created_at' => now()->subDays(730)]);
        $recent = $this->log(['created_at' => now()]);
        $consent = $this->log(['action' => 'consent_updated', 'resource_type' => 'consent']);
        $revoked = $this->log(['action' => 'revoked', 'resource_type' => 'client']);
        $attached = $this->log(['action' => 'link_attached', 'resource_type' => 'client']);
        $future = $this->log(['action' => 'new_evidence_type']);
        $invitation = $this->log(['provider_id' => null]);
        $consentRead = $this->log(['resource_type' => 'consent']);
        $this->artisan('provider:prune', ['--execute' => true])->assertSuccessful();
        $this->assertDatabaseMissing('provider_access_logs', ['id' => $old->id]);
        foreach ([$boundary, $recent, $consent, $revoked, $attached, $future, $invitation, $consentRead] as $row) {
            $this->assertDatabaseHas('provider_access_logs', ['id' => $row->id]);
        }
    }

    public function test_global_and_provider_holds_preserve_logs_and_files(): void
    {
        $held = $this->log();
        $other = $this->log();
        $path = $this->pdf($held->provider_id);
        config(['provider-retention.global_hold' => true]);
        $this->artisan('provider:prune', ['--execute' => true, '--orphan-reports' => true])->assertSuccessful();
        $this->assertDatabaseCount('provider_access_logs', 2);
        Storage::disk('local')->assertExists($path);
        config(['provider-retention.global_hold' => false, 'provider-retention.held_provider_ids' => (string) $held->provider_id]);
        $this->artisan('provider:prune', ['--execute' => true, '--orphan-reports' => true])->assertSuccessful();
        $this->assertDatabaseHas('provider_access_logs', ['id' => $held->id]);
        $this->assertDatabaseMissing('provider_access_logs', ['id' => $other->id]);
        Storage::disk('local')->assertExists($path);
    }

    public function test_only_old_unreferenced_generated_pdfs_are_deleted(): void
    {
        $provider = $this->provider();
        $orphan = $this->pdf($provider->id);
        $referenced = $this->pdf($provider->id);
        $recent = $this->pdf($provider->id, false);
        $other = 'provider-reports/'.$provider->id.'/important.pdf';
        Storage::disk('local')->put($other, 'preserve');
        touch(Storage::disk('local')->path($other), now()->subDays(2)->timestamp);
        $report = ProviderReport::create(['provider_id' => $provider->id, 'user_id' => User::factory()->create()->id,
            'type' => 'monthly', 'period_start' => '2026-01-01', 'period_end' => '2026-01-31',
            'generated_at' => now()->subYears(3), 'file_path' => $referenced]);
        $this->artisan('provider:prune', ['--execute' => true, '--orphan-reports' => true])->assertSuccessful();
        Storage::disk('local')->assertMissing($orphan);
        foreach ([$referenced, $recent, $other] as $path) Storage::disk('local')->assertExists($path);
        $this->assertDatabaseHas('provider_reports', ['id' => $report->id]);
        $this->artisan('provider:prune', ['--execute' => true, '--orphan-reports' => true])
            ->expectsOutput('Deleted: 0 routine audit entries; 0 orphan report PDFs.')->assertSuccessful();
    }

    public function test_unset_window_and_no_orphan_option_delete_nothing(): void
    {
        $this->log();
        $path = $this->pdf($this->provider()->id);
        config(['provider-retention.access_log_days' => null]);
        $this->artisan('provider:prune', ['--execute' => true])->assertSuccessful();
        $this->assertDatabaseCount('provider_access_logs', 1);
        Storage::disk('local')->assertExists($path);
    }

    public function test_concurrent_run_fails_without_deleting(): void
    {
        $this->log();
        $lock = Cache::lock('provider-retention-prune', 3600);
        $this->assertTrue($lock->get());
        try {
            $this->artisan('provider:prune', ['--execute' => true])->assertFailed();
            $this->assertDatabaseCount('provider_access_logs', 1);
        } finally { $lock->release(); }
        $this->artisan('provider:prune', ['--execute' => true])->assertSuccessful();
        $this->assertDatabaseCount('provider_access_logs', 0);
    }

    public function test_batch_pruning_continues_beyond_five_hundred_entries(): void
    {
        $provider = $this->provider();
        $row = ['provider_id' => $provider->id, 'action' => 'view', 'resource_type' => 'mood', 'created_at' => now()->subDays(731)];
        ProviderAccessLog::insert(array_fill(0, 501, $row));
        $this->artisan('provider:prune', ['--execute' => true])
            ->expectsOutput('Deleted: 501 routine audit entries; 0 orphan report PDFs.')->assertSuccessful();
        $this->assertDatabaseCount('provider_access_logs', 0);
    }
}
