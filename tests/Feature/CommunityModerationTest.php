<?php

namespace Tests\Feature;

use App\Jobs\SendCommunityModerationNotice;
use App\Mail\BrandedNoticeMail;
use App\Models\{FeatureFlag, User};
use App\Services\{AdminContentVersion, CommunityModeration};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Mail};
use Illuminate\Support\Str;
use Tests\TestCase;

class CommunityModerationTest extends TestCase
{
    use RefreshDatabase;
    protected function setUp(): void
    {
        parent::setUp();
        config(['community.release_ready' => true, 'community.guidelines_version' => 'test']);
        FeatureFlag::updateOrCreate(['key' => 'community'], ['enabled' => true]);
        Mail::fake();
    }
    private function login(User $user, bool $admin = false): void
    {
        app('auth')->forgetGuards();
        $this->withToken($user->createToken($admin ? 'cozy-admin-management-v1' : 'device', $admin ? ['admin:moderation'] : ['*'], now()->addMinutes(30))->plainTextToken);
    }
    private function content(User $owner, string $status = 'published'): string
    {
        $id = (string) Str::uuid();
        DB::table('community_posts')->insert(['id' => $id, 'user_id' => $owner->id, 'body' => 'Synthetic post', 'status' => $status, 'is_anonymous' => true,
            'guidelines_version' => 'test', 'guidelines_accepted_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        return $id;
    }
    public function test_ban_is_community_only_and_all_participation_mutations_are_denied(): void
    {
        $user = User::factory()->create(); $admin = User::factory()->create(['role' => 'admin']);
        $id = $this->content($user);
        app(CommunityModeration::class)->ban($admin->id, $user->id, '7d', 'Repeated guideline violations.');
        $this->login($user);
        foreach ([['POST', 'posts'], ['PATCH', "posts/$id"], ['POST', "posts/$id/comments"], ['PUT', "posts/$id/reaction"], ['POST', 'groups/1/join'], ['POST', "posts/$id/image"]] as [$method, $path]) {
            $this->json($method, '/api/v1/community/'.$path, [])->assertForbidden()->assertJsonPath('code', 'community_banned');
            $this->travel(2)->minutes();
        }
        $this->getJson('/api/v1/community/posts')->assertOk();
        $this->getJson('/api/v1/community/moderation')->assertOk()->assertJsonPath('data.active_ban.kind', 'ban');
        $this->postJson("/api/v1/community/posts/$id/report", ['reason' => 'other'])->assertStatus(202);
        $other = User::factory()->create();
        $this->postJson("/api/v1/community/users/{$other->id}/block")->assertOk();
        $this->deleteJson("/api/v1/community/posts/$id")->assertOk();
        $this->assertTrue($user->fresh()->is_active);
    }
    public function test_admin_capability_versions_duplicate_bans_and_lifts(): void
    {
        $user = User::factory()->create(); $admin = User::factory()->create(['role' => 'admin']);
        $this->login($user);
        $this->postJson('/api/v1/admin/community-moderation/bans', ['user_id' => $user->id, 'duration' => '7d', 'reason' => 'A clear reason'])->assertForbidden();
        $this->login($admin, true);
        $ban = $this->postJson('/api/v1/admin/community-moderation/bans', ['user_id' => $user->id, 'duration' => '7d', 'reason' => 'A clear reason'])->assertCreated()->json('data');
        $this->postJson('/api/v1/admin/community-moderation/bans', ['user_id' => $user->id, 'duration' => '7d', 'reason' => 'A clear reason'])->assertConflict();
        $url = '/api/v1/admin/community-moderation/bans/'.$ban['id'].'/lift';
        $this->postJson($url, ['expected_version' => (string) Str::uuid(), 'reason' => 'Reviewed and lifted'])->assertConflict();
        $this->postJson($url, ['expected_version' => $ban['version'], 'reason' => 'Reviewed and lifted'])->assertOk();
        $this->postJson($url, ['expected_version' => $ban['version'], 'reason' => 'Reviewed and lifted'])->assertConflict();
        $this->assertNull(app(CommunityModeration::class)->active($user->id));
    }
    public function test_fixed_durations_and_expiry_are_exact_and_notices_deduplicate(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        foreach (['24h' => 1, '7d' => 7, '30d' => 30, 'permanent' => null] as $duration => $days) {
            $user = User::factory()->create(); $service = app(CommunityModeration::class);
            $ban = $service->ban($admin->id, $user->id, $duration, 'Synthetic guideline reason');
            $this->assertSame($days ? now()->addDays($days)->toDateTimeString() : null, $ban->expires_at);
            if ($days) {
                $this->travel($days)->days();
                $this->assertNull($service->active($user->id));
                $service->expire($user->id); $service->expire($user->id);
                $this->assertSame(2, DB::table('community_moderation_notices')->where('user_id', $user->id)->count());
                $this->travelBack();
            }
        }
    }
    public function test_owned_idempotent_appeal_can_be_reviewed_by_the_original_admin(): void
    {
        $user = User::factory()->create(); $other = User::factory()->create(); $admin = User::factory()->create(['role' => 'admin']);
        $ban = app(CommunityModeration::class)->ban($admin->id, $user->id, 'permanent', 'Synthetic guideline reason');
        $this->login($other);
        $this->postJson('/api/v1/community/decisions/'.$ban->id.'/appeal', ['message' => 'Please review the decision'])->assertNotFound();
        $this->login($user);
        config(['plan_access.enabled' => true]);
        $this->getJson('/api/v1/community/moderation')->assertOk();
        $url = '/api/v1/community/decisions/'.$ban->id.'/appeal';
        $first = $this->postJson($url, ['message' => 'Please review the decision'])->assertOk()->json('data.id');
        $this->postJson($url, ['message' => 'Duplicate submission'])->assertOk()->assertJsonPath('data.id', $first);
        $this->assertSame(1, DB::table('community_appeals')->count());
        $row = DB::table('community_appeals')->first();
        config(['plan_access.enabled' => false]);
        $this->assertStringNotContainsString('Please review', $row->message);
        $this->login($admin, true);
        $path = '/api/v1/admin/community-moderation/appeals/'.$row->id.'/review';
        $data = ['expected_version' => $row->version, 'decision' => 'accepted', 'reason' => 'Reviewed and accepted'];
        $this->postJson($path, $data)->assertOk();
        $this->postJson($path, $data)->assertConflict();
        $this->assertNull(app(CommunityModeration::class)->active($user->id));
        $this->assertSame(4, DB::table('community_moderation_notices')->count());
    }
    public function test_content_appeal_requires_confirmation_and_unchanged_existing_target(): void
    {
        $admin = User::factory()->create(['role' => 'admin']); $user = User::factory()->create();
        $this->login($admin, true); $id = $this->content($user);
        $version = AdminContentVersion::token(DB::table('community_posts')->where('id', $id)->first());
        $this->putJson('/api/v1/admin/community/posts/'.$id, ['status' => 'removed', 'expected_version' => $version, 'reason' => 'Synthetic moderation reason'])->assertOk();
        $decision = DB::table('community_decisions')->where('target_id', $id)->first();
        $appeal = app(CommunityModeration::class)->appeal($user->id, $decision->id, 'Please review this removal.');
        $path = '/api/v1/admin/community-moderation/appeals/'.$appeal->id.'/review';
        $data = ['expected_version' => $appeal->version, 'decision' => 'accepted', 'reason' => 'Content is allowed'];
        $this->postJson($path, $data)->assertUnprocessable();
        DB::table('community_posts')->where('id', $id)->update(['body' => 'Changed content']);
        $this->postJson($path, [...$data, 'confirm_republish' => true])->assertConflict();
        DB::table('community_posts')->where('id', $id)->update(['body' => 'Synthetic post']);
        $this->postJson($path, [...$data, 'confirm_republish' => true])->assertOk();
        $this->assertDatabaseHas('community_posts', ['id' => $id, 'status' => 'published']);
    }
    public function test_deleted_content_is_not_restored_and_rejection_does_not_lift_ban(): void
    {
        $admin = User::factory()->create(['role' => 'admin']); $user = User::factory()->create(); $service = app(CommunityModeration::class);
        $id = $this->content($user, 'removed');
        DB::transaction(fn () => $service->removed($admin->id, 'post', $id, 'Synthetic removal reason'));
        $decision = DB::table('community_decisions')->where('target_id', $id)->first();
        $appeal = $service->appeal($user->id, $decision->id, 'Please review this removal.');
        DB::table('community_posts')->where('id', $id)->update(['deleted_at' => now()]);
        $this->login($admin, true);
        $this->postJson('/api/v1/admin/community-moderation/appeals/'.$appeal->id.'/review', ['expected_version' => $appeal->version, 'decision' => 'accepted', 'reason' => 'Content is allowed', 'confirm_republish' => true])->assertConflict();
        $ban = $service->ban($admin->id, $user->id, '7d', 'Synthetic ban reason');
        $appeal = $service->appeal($user->id, $ban->id, 'Please reconsider restriction');
        $service->review($admin->id, $appeal->id, $appeal->version, 'rejected', 'Restriction remains justified', false);
        $this->assertNotNull($service->active($user->id));
    }
    public function test_notice_delivery_deduplicates_retries_and_suppresses_changed_recipient(): void
    {
        $user = User::factory()->create(); $admin = User::factory()->create(['role' => 'admin']);
        app(CommunityModeration::class)->ban($admin->id, $user->id, '7d', 'Synthetic reason');
        $row = DB::table('community_moderation_notices')->first();
        $fake = Mail::getFacadeRoot();
        Mail::swap(\Mockery::mock(\Illuminate\Mail\Mailer::class)->shouldReceive('to')->once()->andThrow(new \RuntimeException('Synthetic transport outage'))->getMock());
        (new SendCommunityModerationNotice($row->id))->handle();
        $this->assertDatabaseHas('community_moderation_notices', ['id' => $row->id, 'status' => 'failed', 'attempts' => 1]);
        Mail::swap($fake); $this->travel(6)->minutes();
        (new SendCommunityModerationNotice($row->id))->handle(); (new SendCommunityModerationNotice($row->id))->handle();
        Mail::assertSent(BrandedNoticeMail::class, 1);
        $this->assertDatabaseHas('community_moderation_notices', ['id' => $row->id, 'status' => 'sent', 'attempts' => 2]);
        app(CommunityModeration::class)->notice($user->id, 'second', 'Test notice', 'A synthetic notice');
        $second = DB::table('community_moderation_notices')->where('id', '!=', $row->id)->first();
        $user->update(['email' => 'changed@example.test']);
        (new SendCommunityModerationNotice($second->id))->handle();
        $this->assertDatabaseHas('community_moderation_notices', ['id' => $second->id, 'status' => 'obsolete']);
        $user->forceDelete();
        $this->assertSame(0, DB::table('community_moderation_notices')->count());
        $this->assertSame(0, DB::table('community_decisions')->count());
    }
}
