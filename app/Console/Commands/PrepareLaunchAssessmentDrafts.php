<?php

namespace App\Console\Commands;

use App\Http\Controllers\AdminQuizController;
use App\Models\{Quiz, User};
use App\Services\CommunityAvailability;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PrepareLaunchAssessmentDrafts extends Command
{
    protected $signature = 'assessments:prepare-launch-drafts {--admin-id= : Existing active administrator responsible for preparation} {--apply : Save unpublished drafts}';

    protected $description = 'Prepare five owner-supplied Cozy well-being drafts in development only; never publish or replace existing content';

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing']) && ! (
            config('community.railway_project_id') === CommunityAvailability::DEVELOPMENT_PROJECT
            && config('community.railway_environment_id') === CommunityAvailability::DEVELOPMENT_ENVIRONMENT
            && rtrim((string) config('app.url'), '/') === 'https://api-dev.cozyhealth.org'
        )) {
            $this->error('Refusing: use local/testing or the explicitly verified Railway development API.');
            return self::FAILURE;
        }

        $files = glob(database_path('data/launch-assessments/*.json'));
        if ($files === false || count($files) !== 5) {
            $this->error('Exactly five launch draft definitions are required.');
            return self::FAILURE;
        }
        $definitions = array_map(fn ($file) => json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR), $files);
        if (! $this->option('apply')) {
            foreach ($definitions as $definition) $this->line($definition['title']);
            $this->info('Preview only. Use --apply --admin-id=<id> to save drafts. No publication is performed.');
            return self::SUCCESS;
        }

        $admin = User::whereKey($this->option('admin-id'))->where('role', 'admin')->where('is_active', true)->first();
        if (! $admin) {
            $this->error('An existing active administrator --admin-id is required.');
            return self::FAILURE;
        }

        DB::transaction(function () use ($admin, $definitions) {
            // Serialize repeated imports without changing any existing drafts or approvals.
            User::whereKey($admin->id)->lockForUpdate()->firstOrFail();
            foreach ($definitions as $definition) {
                $existing = DB::table('admin_quiz_drafts')->where('definition->slug', $definition['slug'])->first();
                if ($existing || Quiz::where('slug', $definition['slug'])->exists()) {
                    $this->line('Preserved existing: '.$definition['title']);
                    continue;
                }
                $request = Request::create('/api/v1/admin/quizzes', 'POST', [
                    'title' => $definition['title'], 'definition' => $definition,
                ]);
                $request->setUserResolver(fn () => $admin);
                $response = app(AdminQuizController::class)->store($request);
                $this->line('Draft '.$response->getData(true)['data']['id'].': '.$definition['title']);
            }
        });

        $this->info('Draft preparation complete. Review questions, proposed scoring and usage rights in Admin > Assessments. Nothing was published.');
        return self::SUCCESS;
    }
}
