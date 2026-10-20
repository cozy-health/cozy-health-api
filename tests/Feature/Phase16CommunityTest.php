<?php

namespace Tests\Feature;

use App\Models\FeatureFlag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class Phase16CommunityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['community.release_ready' => true, 'community.guidelines_version' => 'test-v1']);
        FeatureFlag::updateOrCreate(['key' => 'community'], ['enabled' => true]);
    }

    private function login(?User $user = null): User
    {
        $user ??= User::factory()->create();
        app('auth')->forgetGuards();
        $this->withToken($user->createToken('device')->plainTextToken);

        return $user;
    }

    private function createPost(array $data = []): string
    {
        return $this->postJson('/api/v1/community/posts', [...[
            'body' => 'A fixture post', 'guidelines_accepted' => true, 'guidelines_version' => 'test-v1',
        ], ...$data])->assertOk()->json('data.id');
    }

    public function test_reactions_are_idempotent_owned_and_respect_blocks(): void
    {
        $author = $this->login();
        $id = $this->createPost();
        $reader = $this->login();
        $path = "/api/v1/community/posts/$id/reaction";
        $this->putJson($path, ['liked' => true])->assertOk()->assertJsonPath('data.count', 1);
        $this->putJson($path, ['liked' => true])->assertOk()->assertJsonPath('data.count', 1);
        $this->getJson("/api/v1/community/posts/$id")->assertOk()->assertJsonPath('data.is_liked', true);
        $this->login($author);
        $this->getJson("/api/v1/community/posts/$id")->assertOk()->assertJsonPath('data.is_liked', false);
        $this->putJson($path, ['liked' => false])->assertOk()->assertJsonPath('data.count', 1);
        $this->login($reader);
        $this->postJson("/api/v1/community/users/{$author->id}/block")->assertOk();
        $this->putJson($path, ['liked' => true])->assertNotFound();
    }

    public function test_public_author_posts_never_expose_anonymous_posts(): void
    {
        $author = $this->login();
        $public = $this->createPost();
        $this->createPost(['is_anonymous' => true]);
        $this->login();
        $this->getJson("/api/v1/community/users/{$author->id}/posts")
            ->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.data.0.id', $public);
    }

    public function test_feed_detail_and_comments_hide_anonymous_identity(): void
    {
        $owner = $this->login();
        $id = $this->createPost(['is_anonymous' => true]);
        $this->postJson("/api/v1/community/posts/$id/comments", ['body' => 'Fixture comment', 'is_anonymous' => true])->assertOk();
        $this->login();
        $this->getJson("/api/v1/community/posts/$id")->assertOk()->assertJsonPath('data.author.id', null)
            ->assertJsonPath('data.author.name', 'Anonymous')->assertJsonMissingPath('data.user_id')->assertJsonMissingPath('data.crisis_support_recommended');
        $this->getJson("/api/v1/community/posts/$id/comments")->assertOk()->assertJsonPath('data.data.0.author.id', null);
        $this->getJson('/api/v1/community/posts')->assertOk()->assertJsonPath('data.total', 1)->assertJsonMissing(['author_name' => $owner->name]);
    }

    public function test_only_owner_can_edit_or_delete_and_edits_enter_review(): void
    {
        $owner = $this->login();
        $id = $this->createPost();
        $this->login();
        $this->patchJson("/api/v1/community/posts/$id", ['body' => 'Changed'])->assertNotFound();
        $this->deleteJson("/api/v1/community/posts/$id")->assertNotFound();
        $this->login($owner);
        $this->patchJson("/api/v1/community/posts/$id", ['body' => 'Changed'])->assertOk()->assertJsonPath('data.status', 'pending');
        $this->assertDatabaseHas('community_reports', ['target_id' => $id, 'reason' => 'edit_review']);
        $this->login();
        $this->getJson("/api/v1/community/posts/$id")->assertNotFound();
        $this->login($owner);
        $this->deleteJson("/api/v1/community/posts/$id")->assertOk();
        $this->getJson("/api/v1/community/posts/$id")->assertNotFound();
    }

    public function test_blocks_hide_posts_and_comments_in_both_directions(): void
    {
        $a = $this->login();
        $aPost = $this->createPost();
        $b = $this->login();
        $bPost = $this->createPost();
        $this->postJson("/api/v1/community/posts/$aPost/comments", ['body' => 'From B'])->assertOk();
        $this->login($a);
        $this->postJson('/api/v1/community/users/'.$b->id.'/block')->assertOk();
        $this->getJson('/api/v1/community/posts')->assertJsonPath('data.total', 1);
        $this->getJson("/api/v1/community/posts/$bPost")->assertNotFound();
        $this->getJson("/api/v1/community/posts/$aPost/comments")->assertJsonPath('data.total', 0);
        $this->getJson('/api/v1/community/blocked')->assertJsonPath('data.data.0.id', $b->id);
        $this->login($b);
        $this->getJson("/api/v1/community/posts/$aPost")->assertNotFound();
        $this->getJson('/api/v1/community/posts')->assertJsonPath('data.total', 1);
        $this->login($a);
        $this->deleteJson('/api/v1/community/users/'.$b->id.'/block')->assertOk();
        $this->getJson('/api/v1/community/posts')->assertJsonPath('data.total', 2);
        $this->postJson('/api/v1/community/users/'.$a->id.'/block')->assertUnprocessable();
        $this->postJson('/api/v1/community/users/0'.$a->id.'/block')->assertUnprocessable();
    }

    public function test_reports_are_persistent_idempotent_and_have_a_moderation_deadline(): void
    {
        $this->login();
        $id = $this->createPost();
        $comment = $this->postJson("/api/v1/community/posts/$id/comments", ['body' => 'Fixture'])->assertOk()->json('data.id');
        $this->login();
        $this->postJson("/api/v1/community/posts/$id/report", ['reason' => 'spam'])->assertStatus(202);
        $this->postJson("/api/v1/community/posts/$id/report", ['reason' => 'spam'])->assertStatus(202);
        $this->postJson("/api/v1/community/comments/$comment/report", ['reason' => 'harassment'])->assertStatus(202);
        $this->assertDatabaseCount('community_reports', 2);
        $report = DB::table('community_reports')->where('target_id', $id)->first();
        $this->assertNotNull($report->due_at);
        $this->assertSame('pending', $report->status);
        $this->assertStringContainsString('A fixture post', $report->snapshot);
    }

    public function test_group_posts_require_membership_and_leave_removes_access(): void
    {
        $this->login();
        $group = DB::table('community_groups')->insertGetId(['name' => 'Fixture group', 'description' => 'Fixture', 'is_active' => true]);
        $this->getJson("/api/v1/community/groups/$group/posts")->assertNotFound();
        $this->postJson("/api/v1/community/groups/$group/join")->assertOk();
        $this->postJson("/api/v1/community/groups/$group/join")->assertOk();
        $this->assertDatabaseCount('community_memberships', 1);
        $id = $this->createPost(['group_id' => $group]);
        $this->getJson("/api/v1/community/groups/$group/posts")->assertOk()->assertJsonPath('data.total', 1);
        $this->getJson('/api/v1/community/groups')->assertJsonPath('data.data.0.joined', true);
        $this->deleteJson("/api/v1/community/groups/$group/leave")->assertOk();
        $this->getJson("/api/v1/community/posts/$id")->assertNotFound();
        // Check membership denial independently of the write rate limit.
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        $this->postJson("/api/v1/community/posts/$id/image", ['image' => UploadedFile::fake()->image('private-group.jpg')])->assertNotFound();
    }

    public function test_comment_parent_must_be_visible_and_belong_to_same_post(): void
    {
        $owner = $this->login();
        $a = $this->createPost();
        $b = $this->createPost();
        $comment = $this->postJson("/api/v1/community/posts/$a/comments", ['body' => 'Parent'])->assertOk()->json('data.id');
        $this->postJson("/api/v1/community/posts/$b/comments", ['body' => 'Reply', 'parent_id' => $comment])->assertNotFound();
        $this->postJson("/api/v1/community/posts/$a/comments", ['body' => 'Reply', 'parent_id' => $comment])->assertOk();
        $this->login();
        $this->deleteJson("/api/v1/community/comments/$comment")->assertNotFound();
        $this->login($owner);
        $this->deleteJson("/api/v1/community/comments/$comment")->assertOk();
    }

    public function test_crisis_signal_is_only_returned_to_author_and_enters_review(): void
    {
        $owner = $this->login();
        $id = $this->createPost(['body' => 'I want to kill myself,']);
        $this->getJson("/api/v1/community/posts/$id")->assertJsonPath('data.crisis_support_recommended', true);
        $this->assertDatabaseHas('community_reports', ['target_id' => $id, 'reason' => 'safety']);
        $this->login();
        $this->getJson("/api/v1/community/posts/$id")->assertNotFound();
        $this->getJson('/api/v1/community/posts')->assertJsonPath('data.total', 0);
    }

    public function test_community_is_unavailable_without_release_readiness_and_posts_require_guidelines(): void
    {
        $this->login();
        $this->postJson('/api/v1/community/posts', ['body' => 'Fixture'])->assertUnprocessable();
        config(['community.release_ready' => false]);
        $this->getJson('/api/v1/community/posts')->assertStatus(503);
        $this->assertDatabaseCount('community_posts', 0);
        config(['community.release_ready' => true]);
        FeatureFlag::where('key', 'community')->delete();
        $this->getJson('/api/v1/community/posts')->assertStatus(503);
    }

    public function test_post_and_image_are_created_together_in_pending_review(): void
    {
        \Illuminate\Support\Facades\Storage::fake(config('avatars.disk'));
        $this->login();
        $id = $this->createPost(['image' => UploadedFile::fake()->image('photo.jpg')]);
        $post = DB::table('community_posts')->where('id', $id)->first();
        $this->assertSame('pending', $post->status);
        $this->assertNotNull($post->image_object_key);
        $this->deleteJson('/api/v1/account')->assertOk();
        $this->assertDatabaseHas('avatar_photo_deletions', ['object_key' => $post->image_object_key]);
    }

    public function test_images_are_reencoded_private_and_hidden_until_review(): void
    {
        \Illuminate\Support\Facades\Storage::fake(config('avatars.disk'));
        $owner = $this->login();
        $id = $this->createPost();
        $this->postJson("/api/v1/community/posts/$id/image", ['image' => UploadedFile::fake()->create('unsafe.txt', 1)])->assertUnprocessable();
        $this->postJson("/api/v1/community/posts/$id/image", ['image' => UploadedFile::fake()->image('fixture.png', 1400, 700)])
            ->assertOk()->assertJsonPath('data.status', 'pending');
        $key = DB::table('community_posts')->where('id', $id)->value('image_object_key');
        $disk = \Illuminate\Support\Facades\Storage::disk(config('avatars.disk'));
        $disk->assertExists($key);
        // Windows local fake disks cannot represent Unix private permissions.
        if (PHP_OS_FAMILY !== 'Windows') $this->assertSame('private', $disk->getVisibility($key));
        $info = getimagesizefromstring($disk->get($key));
        $this->assertSame([1280, 640, IMAGETYPE_JPEG], array_slice($info, 0, 3));
        $this->assertDatabaseHas('community_reports', ['target_id' => $id, 'status' => 'pending']);
        $this->login();
        $this->getJson("/api/v1/community/posts/$id")->assertNotFound();
        $this->postJson("/api/v1/community/posts/$id/image", ['image' => UploadedFile::fake()->image('other.png')])->assertNotFound();
        $this->login($owner);
        DB::table('community_posts')->where('id', $id)->update(['status' => 'removed']);
        $this->postJson("/api/v1/community/posts/$id/image", ['image' => UploadedFile::fake()->image('other.png')])->assertConflict();
        $this->assertDatabaseHas('community_posts', ['id' => $id, 'status' => 'removed', 'image_object_key' => $key]);
    }

    public function test_account_deletion_scrubs_moderation_snapshots_and_cascades_owned_content(): void
    {
        $owner = $this->login();
        $id = $this->createPost(['body' => 'Private fixture text']);
        $reporter = $this->login();
        $this->postJson("/api/v1/community/posts/$id/report", ['reason' => 'other', 'details' => 'Private report details'])->assertStatus(202);
        $this->login($owner);
        $this->deleteJson('/api/v1/account')->assertOk();
        $this->assertDatabaseMissing('community_posts', ['id' => $id]);
        $report = DB::table('community_reports')->where('target_id', $id)->first();
        $this->assertSame(['account_deleted' => true], json_decode($report->snapshot, true));
        $this->assertNull($report->details);
        $this->assertSame('pending', $report->status);
        $this->assertSame($reporter->id, (int) $report->reporter_id);
    }
}
