<?php

namespace Tests\Feature\Provider;

use App\Models\JournalEntry;
use App\Models\MoodEntry;
use App\Models\ProviderReport;
use App\Models\QuizAttempt;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;

class ProviderReportTest extends ProviderDataTestCase
{
    private array $viewData = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->travelTo(now()->setDate(2026, 10, 9)->setTime(12, 0));
        View::composer('provider.reports.monthly', function ($view) {
            $this->viewData = $view->getData();
        });
    }

    private function generate($provider, $user): array
    {
        $response = $this->asProvider($provider)->postJson('/api/v1/provider/reports', ['user_id' => $user->id, 'from' => '2026-10-01', 'to' => '2026-10-31'])->assertCreated();

        return $response->json('report');
    }

    private function html(): string
    {
        return view('provider.reports.monthly', $this->viewData)->render();
    }

    public function test_report_generates_pdf_and_audits_each_read(): void
    {
        $provider = $this->provider(['name' => 'Dr. Avery', 'practice' => 'Cozy Clinic']);
        $user = User::factory()->create(['name' => 'Jane PrivateSurname']);
        $link = $this->link($provider, $user);
        $report = $this->generate($provider, $user);
        $stored = ProviderReport::findOrFail($report['id']);
        Storage::disk('local')->assertExists($stored->file_path);
        $ciphertext = Storage::disk('local')->get($stored->file_path);
        $this->assertStringStartsWith(\App\Services\Provider\EncryptedReportStorage::PREFIX, $ciphertext);
        $this->assertStringStartsWith('%PDF-', app(\App\Services\Provider\EncryptedReportStorage::class)->decrypt($ciphertext));
        $this->assertStringStartsWith('provider-reports/'.$provider->id.'/', $stored->file_path);
        $this->assertArrayNotHasKey('file_path', $report);
        $this->assertStringContainsString('Jane', $this->html());
        $this->assertStringNotContainsString('PrivateSurname', $this->html());
        $this->assertStringContainsString('confidential', $this->html());
        $this->getJson('/api/v1/provider/reports')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/provider/reports/'.$stored->id)->assertOk();
        $download = $this->getJson('/api/v1/provider/reports/'.$stored->id.'/download')->assertOk();
        $this->get($download->json('download_url'))->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertHeader('Cache-Control', 'no-store, private');
        foreach (['create', 'list', 'view', 'download'] as $action) {
            $this->assertDatabaseHas('provider_access_logs', ['provider_id' => $provider->id, 'user_id' => $user->id, 'link_id' => $link->id, 'action' => $action, 'resource_type' => 'report', 'resource_id' => (string) $stored->id]);
        }
    }

    public function test_report_respects_consent_flags(): void
    {
        $provider = $this->provider();
        $user = User::factory()->create();
        $link = $this->link($provider, $user);
        $this->generate($provider, $user);
        foreach (['Mood summary', 'Journal metadata summary', 'Quiz summary', 'Provider notes summary'] as $section) {
            $this->assertStringNotContainsString($section, $this->html());
        }
        $link->update(['consent_moods' => true, 'consent_quizzes' => true, 'consent_notes' => true]);
        MoodEntry::create(['id' => (string) Str::uuid(), 'user_id' => $user->id, 'mood' => 'happy', 'intensity' => 4, 'triggers' => ['Work', 'Work', 'Sleep'], 'client_created_at' => now()]);
        QuizAttempt::create(['id' => (string) Str::uuid(), 'user_id' => $user->id, 'quiz_slug' => 'wellbeing', 'quiz_title' => 'Wellbeing', 'answers' => [], 'score' => 8, 'interpretation' => 'Quiz interpretation', 'completed_at' => now()]);
        $provider->notes()->create(['user_id' => $user->id, 'body' => 'Own provider note']);
        $this->provider()->notes()->create(['user_id' => $user->id, 'body' => 'Other provider secret']);
        $this->generate($provider, $user);
        $this->assertSame(1, $this->viewData['summary']['moods']['count']);
        $this->assertSame(['Work' => 1, 'Sleep' => 1], $this->viewData['summary']['moods']['triggers']);
        foreach (['Mood summary', 'Quiz summary', 'Provider notes summary', 'Quiz interpretation', 'Own provider note'] as $text) {
            $this->assertStringContainsString($text, $this->html());
        }
        $this->assertStringNotContainsString('Journal metadata summary', $this->html());
        $this->assertStringNotContainsString('Other provider secret', $this->html());
    }

    public function test_report_never_contains_journal_body(): void
    {
        $provider = $this->provider();
        $user = User::factory()->create();
        $this->link($provider, $user, ['consent_journals' => true]);
        JournalEntry::create(['id' => (string) Str::uuid(), 'user_id' => $user->id, 'type' => 'voice', 'title' => 'Secret title',
            'body' => 'Secret body', 'transcription' => 'Secret transcript', 'prompt_text' => 'Secret prompt', 'voice_url' => 'https://secret.example/audio', 'client_created_at' => now()]);
        JournalEntry::create(['id' => (string) Str::uuid(), 'user_id' => $user->id, 'type' => 'free', 'body' => 'draft', 'is_draft' => true, 'client_created_at' => now()]);
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            if (str_contains($query->sql, 'journal_entries') && str_starts_with(strtolower($query->sql), 'select')) {
                $queries[] = $query->sql;
            }
        });
        $this->generate($provider, $user);
        $this->assertEquals(['count' => 1, 'types' => ['voice' => 1]], $this->viewData['summary']['journals']);
        $this->assertStringNotContainsString('Secret', $this->html());
        $this->assertStringNotContainsString('secret.example', $this->html());
        $this->assertCount(1, $queries);
        foreach (['body', 'title', 'transcription', 'prompt_text', 'voice_url', '* from'] as $column) {
            $this->assertStringNotContainsString($column, $queries[0]);
        }
    }

    public function test_signed_url_expires_and_tampering_is_rejected(): void
    {
        $provider = $this->provider();
        $user = User::factory()->create();
        $this->link($provider, $user);
        $report = $this->generate($provider, $user);
        $url = $this->getJson('/api/v1/provider/reports/'.$report['id'].'/download')->assertOk()->json('download_url');
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame(now()->addHour()->timestamp, (int) $query['expires']);
        $this->get($url.'&extra=1')->assertForbidden();
        $this->get('/api/v1/provider/reports/'.$report['id'].'/file')->assertForbidden();
        $this->travel(61)->minutes();
        $this->get($url)->assertForbidden();
    }

    public function test_cross_provider_report_access_returns_404(): void
    {
        $provider = $this->provider();
        $user = User::factory()->create();
        $this->link($provider, $user);
        $report = $this->generate($provider, $user);
        $url = $this->getJson('/api/v1/provider/reports/'.$report['id'].'/download')->assertOk()->json('download_url');
        $other = $this->provider();
        $this->link($other, $user);
        $this->app['auth']->forgetGuards();
        $this->asProvider($other)->getJson('/api/v1/provider/reports')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/provider/reports/'.$report['id'])->assertNotFound();
        $this->getJson('/api/v1/provider/reports/'.$report['id'].'/download')->assertNotFound();
        $this->get($url)->assertNotFound();
    }

    public function test_consent_revocation_blocks_previously_signed_report(): void
    {
        $provider = $this->provider();
        $user = User::factory()->create();
        $link = $this->link($provider, $user, ['consent_moods' => true]);
        $report = $this->generate($provider, $user);
        $url = $this->getJson('/api/v1/provider/reports/'.$report['id'].'/download')->assertOk()->json('download_url');
        $link->update(['consent_moods' => false]);
        $this->get($url)->assertNotFound();
        $this->getJson('/api/v1/provider/reports/'.$report['id'].'/download')->assertNotFound();
        $safeReport = $this->generate($provider, $user);
        $this->getJson('/api/v1/provider/reports/'.$safeReport['id'].'/download')->assertOk();
        $link->update(['revoked_at' => now()]);
        $this->getJson('/api/v1/provider/reports')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/provider/reports/'.$safeReport['id'])->assertNotFound();
    }

    public function test_generation_requires_active_link_and_valid_period(): void
    {
        $provider = $this->provider();
        $user = User::factory()->create();
        $path = '/api/v1/provider/reports';
        $this->getJson($path)->assertUnauthorized();
        $this->asProvider($provider)->postJson($path, ['user_id' => $user->id])->assertNotFound();
        $link = $this->link($provider, $user, ['verified_at' => null]);
        $this->postJson($path, ['user_id' => $user->id])->assertNotFound();
        $link->update(['verified_at' => now()]);
        $this->postJson($path, ['user_id' => $user->id, 'from' => '2026-10-09', 'to' => '2026-10-01'])->assertUnprocessable();
        $this->postJson($path, ['user_id' => $user->id, 'from' => '2024-01-01', 'to' => '2026-10-01'])->assertUnprocessable();
        $this->postJson($path, ['user_id' => $user->id, 'from' => '2026-10-01'])->assertUnprocessable();
        $this->postJson($path, ['user_id' => $user->id, 'type' => 'unknown'])->assertUnprocessable();
        $this->assertDatabaseCount('provider_reports', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }
}
