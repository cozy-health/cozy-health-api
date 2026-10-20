<?php
namespace App\Console\Commands;
use Illuminate\Console\Command;
class LaunchReadiness extends Command
{
    protected $signature = 'launch:readiness';
    protected $description = 'Report launch configuration gates without exposing credentials or enabling features';
    public function handle(): int
    {
        $artifact = json_decode(file_get_contents(resource_path('legal/launch.json')), true, flags: JSON_THROW_ON_ERROR);
        $checks = [
            'Legal source review issues resolved' => empty($artifact['review_issues']),
            'Consumer and provider documents approved' => collect([...$artifact['documents'], ...$artifact['provider_documents']])->every(fn ($doc) => $doc['published']),
            'Apple signing identity configured' => filled(config('launch.apple.issuer_id')) && filled(config('launch.apple.key_id')) && filled(config('launch.apple.private_key')) && filled(config('launch.apple.bundle_id')),
            'Apple notification trust anchor configured' => filled(config('launch.apple.root_ca')) && is_file(config('launch.apple.root_ca')),
            'Google Play credentials configured' => filled(config('launch.google.package_name')) && filled(config('launch.google.credentials')),
            'Google notification identity configured' => filled(config('launch.google.webhook_audience')) && filled(config('launch.google.webhook_email')),
            'Store product mappings configured' => count(config('launch.products')) > 0,
            'Student review enabled after retention approval' => (bool) config('launch.student.enabled'),
            'Emergency email allowance configured' => config('launch.emergency_email.daily_limit') > 0,
            'Transactional mail transport selected' => !in_array(config('mail.default'), ['log', 'array'], true),
        ];
        $this->table(['Release gate', 'Configuration'], collect($checks)->map(fn ($ready, $label) => [$label, $ready ? 'Present — operational verification still required' : 'Pending'])->all());
        $this->line('Developer enrollment, DNS/hosting access, mailbox ownership, PostHog free-plan settings, guardian requirements and signed-device/store acceptance require external verification.');
        return in_array(false, $checks, true) ? self::FAILURE : self::SUCCESS;
    }
}
