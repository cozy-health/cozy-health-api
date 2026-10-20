<?php
namespace App\Console\Commands;
use App\Services\SettingsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
class PublishLaunchDocuments extends Command
{
    protected $signature = 'legal:publish {--apply : Persist reviewed documents, without enabling enforcement}';
    protected $description = 'Validate the generated website policy artifact before consent publication';
    public function handle(SettingsService $settings): int
    {
        $manifest = json_decode(file_get_contents(resource_path('legal/launch.json')), true, flags: JSON_THROW_ON_ERROR);
        $this->info('Website content SHA-256: '.$manifest['source_sha256']);
        if (!$this->option('apply')) { $this->info('Validation preview only. No settings changed.'); $this->line(implode("\n", $manifest['review_issues'])); return self::SUCCESS; }
        if ($manifest['review_issues'] || collect([...$manifest['documents'], ...$manifest['provider_documents']])->contains(fn ($doc) => !$doc['published'])) {
            $this->error('Resolve review issues and mark the source documents published before applying. Regenerate artifacts first.'); return self::FAILURE;
        }
        DB::transaction(function () use ($settings, $manifest) {
            $settings->set('consent.documents', $manifest['documents']);
            $settings->set('consent.provider_documents', $manifest['provider_documents']);
        });
        $this->info('Documents published. Enforcement was not changed; verify website and clients before activation.');
        return self::SUCCESS;
    }
}
