<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class CheckGuardianRelease extends Command
{
    protected $signature = 'guardians:release-check {--schema : Also verify the additive migration is applied}';

    protected $description = 'Read-only check for the disabled age/guardian deployment; does not approve activation';

    public function handle(): int
    {
        $checks = [
            'Age enforcement is disabled' => config('age.enabled') === false,
            'Guardian invitations are disabled' => config('age.invitations_enabled') === false,
            'Minor health-data readiness is disabled' => config('age.health_data_ready') === false,
            'No minor countries are enabled' => config('age.approved_countries') === [],
        ];

        if ($this->option('schema')) {
            try {
                $checks['User age declaration columns exist'] = Schema::hasColumns('users', [
                    'date_of_birth', 'residence_country', 'age_declared_at', 'age_disputed',
                ]);
                foreach (['guardian_authorizations', 'age_authorization_events', 'age_support_cases'] as $table) {
                    $checks[$table.' table exists'] = Schema::hasTable($table);
                }
            } catch (\Throwable) {
                // Connection errors can contain credentials or private hosts.
                $this->error('Schema verification failed. Check database connectivity privately.');

                return self::FAILURE;
            }
        }

        foreach ($checks as $label => $passed) {
            $this->line(($passed ? 'PASS: ' : 'FAIL: ').$label);
        }

        $this->line('Read-only deployment check. Device, inbox, isolation and activation acceptance are separate.');

        return in_array(false, $checks, true) ? self::FAILURE : self::SUCCESS;
    }
}
