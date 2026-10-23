<?php

namespace App\Console\Commands;

use App\Models\{FeatureFlag, PushDevice, User, UserProfile};
use App\Services\{CommunityAvailability, DocumentConsentService};
use Illuminate\Console\Command;
use Illuminate\Support\Facades\{Cache, DB, Http};
use Illuminate\Support\Str;

class CommunityDevelopment extends Command
{
    protected $signature = 'community:development {--apply : Enable the development feature flag} {--fixtures : Create labelled test accounts and group} {--verify : Exercise the development HTTP API using temporary test credentials}';
    protected $description = 'Preview or activate Community in the approved Railway development environment only';
    public const GROUP = '[Development test] Community playground';
    public const DESCRIPTION = 'Synthetic development testing only. Do not add private health information.';
    private array $tokens = [];

    public function handle(CommunityAvailability $availability): int
    {
        if (! $availability->developmentTesting()) {
            $this->error('Refusing: the development switch, Railway project/environment and API hostname must all match.');
            return self::FAILURE;
        }
        $guidelines = app(DocumentConsentService::class)->configuration()['documents']['ugc_guidelines'] ?? null;
        if (! $guidelines || ! $guidelines['published'] || blank($guidelines['content'])
            || $guidelines['version'] !== config('community.guidelines_version')) {
            $this->error('Refusing: configure the current published Community Guidelines version first.');
            return self::FAILURE;
        }
        if (! $this->option('apply')) {
            if ($this->option('fixtures') || $this->option('verify')) {
                $this->error('Use --apply explicitly for fixture creation or verification writes.');
                return self::FAILURE;
            }
            $this->info('Preview only. Development identity and guidelines match; no records changed.');
            return self::SUCCESS;
        }
        try {
            DB::transaction(function () {
                FeatureFlag::updateOrCreate(['key' => 'community'], ['enabled' => true]);
                if ($this->option('fixtures')) $this->fixtures();
            });
            Cache::forget('app:config');
            $this->info('Community enabled for development. Public launch readiness was not changed.');
            if ($this->option('verify')) $this->verify();
            return self::SUCCESS;
        } catch (\Throwable $error) {
            // Never print HTTP response bodies, passwords or temporary bearer credentials.
            $this->error($error instanceof \RuntimeException && str_starts_with($error->getMessage(), 'Development check:')
                ? $error->getMessage() : 'Development setup/verification failed. Inspect the scoped test records and service logs.');
            return self::FAILURE;
        } finally {
            foreach ($this->tokens as $token) $token->delete();
        }
    }

    private function account(string $kind, string $role = 'user'): User
    {
        $name = '[Development test] Community '.$kind;
        $email = 'community-dev-'.$kind.'@example.test';
        $user = User::withTrashed()->where('email', $email)->first();
        if ($user) {
            $this->check(! $user->trashed() && $user->name === $name && $user->role === $role && $user->is_active,
                'fixture identity already exists with different ownership or status');
        } else {
            $user = new User(['name' => $name, 'email' => $email, 'password' => Str::random(64), 'timezone' => 'UTC', 'is_active' => true]);
            $user->forceFill(['role' => $role, 'email_verified_at' => now()])->save();
        }
        UserProfile::withoutGlobalScope('user')->firstOrCreate(['user_id' => $user->id], [
            'id' => (string) Str::uuid(), 'name' => $name, 'email' => $email,
            'notifications_master' => true, 'comments_notifications' => true, 'push_consent' => false,
            'reminder_settings' => ['community_likes_enabled' => true, 'community_push_enabled' => false],
        ]);
        return $user;
    }

    private function fixtures(): void
    {
        $this->account('author');
        $this->account('reader');
        $this->account('moderator', 'admin');
        $group = DB::table('community_groups')->where('name', self::GROUP)->first();
        if ($group) {
            $this->check($group->description === self::DESCRIPTION && (bool) $group->is_active,
                'the labelled group already exists with different settings');
        } else {
            DB::table('community_groups')->insert(['name' => self::GROUP, 'description' => self::DESCRIPTION,
                'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->info('Labelled test accounts and group are ready. No passwords were disclosed or emails sent.');
    }

    private function credential(string $kind, bool $admin = false): string
    {
        $user = User::where('email', 'community-dev-'.$kind.'@example.test')->first();
        $this->check($user && $user->name === '[Development test] Community '.$kind
            && $user->role === ($admin ? 'admin' : 'user'), 'create the labelled fixtures before verifying');
        $profile = UserProfile::withoutGlobalScope('user')->where('user_id', $user->id)->first();
        $this->check(! $profile?->push_consent && ! PushDevice::where('user_id', $user->id)->exists(),
            'verification accounts must have phone delivery disabled and no registered devices');
        $created = $user->createToken($admin ? 'cozy-admin-management-v1' : 'community-development-smoke',
            $admin ? ['admin:moderation', 'admin:content'] : ['*'], now()->addMinutes(10));
        $this->tokens[] = $created->accessToken;
        return $created->plainTextToken;
    }

    private function request(string $token, string $method, string $path, array $data = [], int $expected = 200): array
    {
        $response = Http::baseUrl('https://api-dev.cozyhealth.org/api/v1')->withToken($token)
            ->acceptJson()->asJson()->withoutRedirecting()->timeout(25)->send($method, $path, ['json' => $data]);
        $this->check($response->status() === $expected, "$method $path returned HTTP {$response->status()}, expected $expected");
        return $response->json() ?? [];
    }

    private function check(bool $condition, string $message): void
    {
        if (! $condition) throw new \RuntimeException('Development check: '.$message);
    }

    private function verify(): void
    {
        $author = $this->credential('author');
        $reader = $this->credential('reader');
        $moderator = $this->credential('moderator', true);
        $group = DB::table('community_groups')->where('name', self::GROUP)->first();
        $this->check($group !== null, 'create the labelled test group first');
        $this->request($author, 'GET', '/community/groups');
        foreach ([$author, $reader] as $token) $this->request($token, 'POST', "/community/groups/{$group->id}/join");
        $post = $this->request($author, 'POST', '/community/posts', ['body' => '[Development smoke] Community workflow '.Str::random(8),
            'group_id' => $group->id, 'guidelines_accepted' => true, 'guidelines_version' => config('community.guidelines_version')])['data'];
        $id = $post['id'];
        $this->request($reader, 'GET', "/community/posts/$id");
        $comment = $this->request($reader, 'POST', "/community/posts/$id/comments", ['body' => '[Development smoke] Anonymous reply', 'is_anonymous' => true])['data'];
        $this->check($comment['author']['id'] === null && $comment['author']['name'] === 'Anonymous', 'anonymous reply exposed identity');
        foreach ([true, true] as $liked) $this->request($reader, 'PUT', "/community/posts/$id/reaction", ['liked' => $liked]);
        $notices = $this->request($author, 'GET', '/notifications')['data']['data'];
        $ownNotices = array_values(array_filter($notices, fn ($notice) => ($notice['data']['post_id'] ?? null) === $id));
        $this->check(count($ownNotices) === 2, 'expected exactly one reply and one like in the shared inbox');
        $unread = $this->request($author, 'GET', '/notifications/unread-count')['data']['unread_count'];
        $this->check($unread >= 2, 'unread count does not include the new Community notices');
        foreach ($ownNotices as $notice) $this->request($reader, 'GET', '/notifications/'.$notice['id'], [], 404);
        $notice = $ownNotices[0];
        $this->request($author, 'PATCH', '/notifications/'.$notice['id'].'/read');
        $this->request($author, 'PATCH', '/user/notification-preferences', ['comments_notifications' => false]);
        $this->request($reader, 'POST', "/community/posts/$id/comments", ['body' => '[Development smoke] Muted reply']);
        $notices = $this->request($author, 'GET', '/notifications')['data']['data'];
        $this->check(count(array_filter($notices, fn ($row) => ($row['data']['post_id'] ?? null) === $id)) === 2,
            'reply notification preference was not respected');
        $this->request($author, 'PATCH', '/user/notification-preferences', ['comments_notifications' => true]);
        $this->request($reader, 'POST', "/community/posts/$id/report", ['reason' => 'spam', 'details' => '[Development smoke] Test report; synthetic content only.'], 202);
        $reportId = DB::table('community_reports')->where('target_id', $id)->where('reason', 'spam')->value('id');
        $this->check($reportId !== null, 'test report did not reach moderation');
        $this->request($moderator, 'PUT', "/admin/reports/$reportId", ['decision' => 'dismiss']);
        $authorId = $post['author']['id'];
        $this->request($reader, 'POST', "/community/users/$authorId/block");
        $this->request($reader, 'GET', "/community/posts/$id", [], 404);
        $this->request($reader, 'DELETE', "/community/users/$authorId/block");
        $review = $this->request($moderator, 'GET', "/admin/community/posts/$id")['data'];
        $this->request($moderator, 'PUT', "/admin/community/posts/$id", ['status' => 'removed', 'expected_version' => $review['version']]);
        $this->request($reader, 'GET', "/community/posts/$id", [], 404);
        $review = $this->request($moderator, 'GET', "/admin/community/posts/$id")['data'];
        $this->request($moderator, 'PUT', "/admin/community/posts/$id", ['status' => 'published', 'expected_version' => $review['version'], 'confirm_republish' => true]);
        $this->request($reader, 'GET', "/community/posts/$id");
        $this->request($reader, 'DELETE', "/community/groups/{$group->id}/leave");
        $this->request($reader, 'GET', "/community/groups/{$group->id}/posts", [], 404);
        $this->request($reader, 'POST', "/community/groups/{$group->id}/join");
        $this->info('PASS: live development groups, joining/leaving, posts, anonymous replies, likes/deduplication, inbox/read count, preferences, ownership, reports, blocks and moderation.');
        $this->line('Only labelled synthetic accounts/content were used. Phone delivery was not attempted.');
    }
}
