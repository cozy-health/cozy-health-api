<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminReviewSafetyTest extends TestCase
{
    use RefreshDatabase;

    private function signIn(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        app('auth')->forgetGuards();
        $this->withToken($admin->createToken('cozy-admin-management-v1', ['admin:users', 'admin:moderation', 'admin:content'], now()->addMinutes(30))->plainTextToken);
    }

    private function content(string $kind): string
    {
        $user = User::factory()->create();
        $post = (string) Str::uuid();
        DB::table('community_posts')->insert(['id' => $post, 'user_id' => $user->id, 'body' => 'Test post', 'status' => 'pending', 'is_anonymous' => true, 'guidelines_version' => 'v1', 'guidelines_accepted_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        if ($kind === 'posts') return $post;
        $id = (string) Str::uuid();
        DB::table('community_comments')->insert(['id' => $id, 'post_id' => $post, 'user_id' => $user->id, 'body' => 'Test comment', 'status' => 'pending', 'is_anonymous' => true, 'created_at' => now(), 'updated_at' => now()]);
        return $id;
    }

    private function report(string $kind, string $id): int
    {
        return DB::table('community_reports')->insertGetId(['target_type' => $kind === 'posts' ? 'post' : 'comment', 'target_id' => $id, 'reason' => 'spam', 'snapshot' => '{}', 'due_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function version(string $kind, string $id): string
    {
        return $this->getJson('/api/v1/admin/community/'.$kind.'/'.$id)->assertOk()->assertJsonMissingPath('data.user_id')->json('data.version');
    }

    public function test_user_access_is_read_only_and_does_not_revoke_sessions(): void
    {
        $this->signIn();
        $user = User::factory()->create();
        $user->createToken('mobile');
        $this->getJson('/api/v1/admin/users')->assertOk();
        $this->assertContains($this->putJson('/api/v1/admin/users/'.$user->id, ['name' => 'Changed', 'is_active' => false])->status(), [404, 405]);
        $this->assertContains($this->deleteJson('/api/v1/admin/users/'.$user->id, ['confirmation' => 'DELETE'])->status(), [404, 405]);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => $user->name, 'is_active' => true]);
        $this->assertSame(1, $user->tokens()->count());
    }

    public function test_decisions_resolve_related_reports_and_stale_reports_cannot_republish(): void
    {
        $this->signIn();
        foreach (['posts', 'comments'] as $kind) {
            $id = $this->content($kind);
            $a = $this->report($kind, $id); $b = $this->report($kind, $id);
            $version = $this->version($kind, $id);
            $this->putJson('/api/v1/admin/reports/'.$a, ['decision' => 'remove', 'expected_version' => $version])->assertOk();
            foreach ([$a, $b] as $report) {
                $this->assertDatabaseHas('community_reports', ['id' => $report, 'status' => 'resolved']);
                $this->assertNotNull(DB::table('community_reports')->where('id', $report)->value('resolved_at'));
                $this->assertDatabaseHas('admin_audit_logs', ['resource' => 'community-reports', 'resource_id' => (string) $report, 'action' => 'remove']);
            }
            $this->putJson('/api/v1/admin/reports/'.$b, ['decision' => 'publish', 'expected_version' => $version, 'confirm_republish' => true])->assertConflict();
            $this->assertDatabaseHas('community_'.$kind, ['id' => $id, 'status' => 'removed']);
        }
    }

    public function test_dismissal_is_isolated_and_missing_content_can_be_dismissed(): void
    {
        $this->signIn(); $id = $this->content('posts');
        $a = $this->report('posts', $id); $b = $this->report('posts', $id);
        $this->putJson('/api/v1/admin/reports/'.$a, ['decision' => 'dismiss'])->assertOk();
        $this->assertDatabaseHas('community_reports', ['id' => $b, 'status' => 'pending']);
        $this->assertDatabaseHas('community_posts', ['id' => $id, 'status' => 'pending']);
        DB::table('community_posts')->where('id', $id)->update(['deleted_at' => now()]);
        $this->getJson('/api/v1/admin/reports/'.$b)->assertOk()->assertJsonPath('data.target', null);
        $this->putJson('/api/v1/admin/reports/'.$b, ['decision' => 'dismiss'])->assertOk();
    }

    public function test_direct_moderation_resolves_only_pending_reports_for_the_same_target(): void
    {
        $this->signIn();
        foreach (['posts', 'comments'] as $kind) {
            foreach (['published' => 'publish', 'removed' => 'remove'] as $status => $decision) {
                $id = $this->content($kind);
                $first = $this->report($kind, $id);
                $second = $this->report($kind, $id);
                $other = $this->report($kind, $this->content($kind));
                $resolved = $this->report($kind, $id);
                $oldResolution = now()->subDay()->startOfSecond();
                DB::table('community_reports')->where('id', $resolved)->update(['status' => 'resolved', 'resolved_at' => $oldResolution]);

                $this->putJson('/api/v1/admin/community/'.$kind.'/'.$id, [
                    'status' => $status, 'expected_version' => $this->version($kind, $id),
                ])->assertOk();

                foreach ([$first, $second] as $report) {
                    $this->assertDatabaseHas('community_reports', ['id' => $report, 'status' => 'resolved']);
                    $this->assertNotNull(DB::table('community_reports')->where('id', $report)->value('resolved_at'));
                    $this->assertDatabaseHas('admin_audit_logs', ['resource' => 'community-reports', 'resource_id' => (string) $report, 'action' => $decision]);
                }
                $this->assertDatabaseHas('community_reports', ['id' => $other, 'status' => 'pending']);
                $this->assertDatabaseHas('community_reports', ['id' => $resolved, 'resolved_at' => $oldResolution]);
            }
        }
    }

    public function test_direct_pending_and_rejected_stale_moderation_leave_reports_pending(): void
    {
        $this->signIn();
        $id = $this->content('posts');
        $report = $this->report('posts', $id);
        $version = $this->version('posts', $id);
        $this->putJson('/api/v1/admin/community/posts/'.$id, ['status' => 'pending', 'expected_version' => $version])->assertOk();
        $this->assertDatabaseHas('community_reports', ['id' => $report, 'status' => 'pending']);
        DB::table('community_posts')->where('id', $id)->update(['body' => 'Changed by author']);
        $this->putJson('/api/v1/admin/community/posts/'.$id, ['status' => 'removed', 'expected_version' => $version])->assertConflict();
        $this->assertDatabaseHas('community_reports', ['id' => $report, 'status' => 'pending']);
    }

    public function test_stale_edits_and_missing_versions_are_rejected_for_posts_and_comments(): void
    {
        $this->travelTo(now()->startOfSecond()); $this->signIn();
        foreach (['posts', 'comments'] as $kind) {
            $id = $this->content($kind); $report = $this->report($kind, $id);
            $path = '/api/v1/admin/community/'.$kind.'/'.$id;
            $version = $this->version($kind, $id);
            $this->putJson($path, ['status' => 'published'])->assertUnprocessable()->assertJsonValidationErrors('expected_version');
            $this->putJson('/api/v1/admin/reports/'.$report, ['decision' => 'remove'])->assertUnprocessable();
            // Same-second author edits must also invalidate a moderator's view.
            DB::table('community_'.$kind)->where('id', $id)->update(['body' => 'Edited after review opened']);
            $this->putJson($path, ['status' => 'published', 'expected_version' => $version])->assertConflict();
            $this->putJson('/api/v1/admin/reports/'.$report, ['decision' => 'remove', 'expected_version' => $version])->assertConflict();
            $version = $this->version($kind, $id);
            $this->putJson($path, ['status' => 'removed', 'expected_version' => $version])->assertOk();
            $this->putJson($path, ['status' => 'published', 'expected_version' => $version])->assertConflict();
            $current = $this->version($kind, $id);
            $this->putJson($path, ['status' => 'published', 'expected_version' => $current])->assertUnprocessable();
            $this->putJson($path, ['status' => 'published', 'expected_version' => $current, 'confirm_republish' => true])->assertOk();
        }
    }

    public function test_report_republishing_requires_confirmation_and_details_preserve_anonymity(): void
    {
        $this->signIn(); $id = $this->content('posts');
        DB::table('community_posts')->where('id', $id)->update(['status' => 'removed']);
        $report = $this->report('posts', $id);
        $detail = $this->getJson('/api/v1/admin/reports/'.$report)->assertOk()->assertJsonStructure(['data' => ['reason', 'due_at', 'created_at', 'resolved_at', 'target' => ['status', 'updated_at', 'version']]])->assertJsonMissingPath('data.snapshot')->assertJsonMissingPath('data.target.user_id');
        $version = $detail->json('data.target.version');
        $this->putJson('/api/v1/admin/reports/'.$report, ['decision' => 'publish', 'expected_version' => $version])->assertUnprocessable();
        $this->assertDatabaseHas('community_reports', ['id' => $report, 'status' => 'pending']);
        $this->putJson('/api/v1/admin/reports/'.$report, ['decision' => 'publish', 'expected_version' => $version, 'confirm_republish' => true])->assertOk();
    }

    public function test_required_descriptions_are_validated_on_create_and_update(): void
    {
        $this->signIn();
        foreach (['communities', 'challenges'] as $resource) {
            $body = ['name' => 'Review fixture', 'description' => 'A description', 'is_active' => true];
            if ($resource === 'challenges') $body += ['slug' => 'review-fixture', 'sort_order' => 0, 'duration_days' => 1, 'tasks' => ['Rest']];
            $path = '/api/v1/admin/content/'.$resource;
            foreach (['', '   ', null] as $description) $this->postJson($path, [...$body, 'description' => $description])->assertUnprocessable()->assertJsonValidationErrors('description');
            $id = $this->postJson($path, $body)->assertCreated()->json('data.id');
            $this->putJson($path.'/'.$id, [...$body, 'description' => ''])->assertUnprocessable()->assertJsonValidationErrors('description');
            $this->putJson($path.'/'.$id, [...$body, 'description' => 'Updated description'])->assertOk();
        }
    }
}
