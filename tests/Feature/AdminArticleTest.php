<?php

namespace Tests\Feature;

use App\Models\ContentArticle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminArticleTest extends TestCase
{
    use RefreshDatabase;

    private function admin(array $abilities = ['admin:content']): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['role' => 'admin'])->save();
        app('auth')->forgetGuards();
        $this->withToken($admin->createToken('cozy-admin-management-v1', $abilities, now()->addMinutes(30))->plainTextToken);
    }

    private function draft(): array
    {
        return ['title' => 'Synthetic article', 'slug' => 'synthetic-article', 'category' => 'sleep',
            'excerpt' => 'Synthetic test excerpt.', 'body' => 'Synthetic original article body.', 'read_time_minutes' => 3];
    }

    public function test_drafts_require_content_capability_and_never_become_public_on_save(): void
    {
        $this->getJson('/api/v1/admin/articles')->assertUnauthorized();
        $this->admin(['admin:metrics']);
        $this->postJson('/api/v1/admin/articles', $this->draft())->assertForbidden();
        $this->admin();
        $record = $this->postJson('/api/v1/admin/articles', [...$this->draft(), 'read_time_minutes' => '3'])->assertCreated()->assertJsonPath('data.draft.read_time_minutes', 3)->json('data');
        $this->assertFalse($record['published']);
        $this->assertTrue($record['has_draft']);
        $this->assertSame(0, ContentArticle::published()->count());
        $this->getJson('/api/v1/admin/articles')->assertOk()->assertJsonPath('data.data.0.draft.title', 'Synthetic article');
        $this->postJson('/api/v1/admin/articles/'.$record['id'].'/publish', ['expected_version' => $record['version'], 'approved' => true])->assertUnprocessable();
    }

    public function test_publication_edits_and_unpublication_keep_drafts_private_and_reject_stale_writes(): void
    {
        $this->admin();
        $record = $this->postJson('/api/v1/admin/articles', [...$this->draft(), 'license' => 'Original synthetic fixture'])->assertCreated()->json('data');
        $path = '/api/v1/admin/articles/'.$record['id'];
        $published = $this->postJson($path.'/publish', ['expected_version' => $record['version'], 'approved' => true])->assertOk()->json('data');
        $this->assertTrue($published['published']);
        $this->assertFalse($published['has_draft']);
        $edited = $this->putJson($path, [...$this->draft(), 'title' => 'Unpublished revision', 'body' => 'Unpublished draft body.', 'license' => 'Original synthetic fixture', 'expected_version' => $published['version']])->assertOk()->json('data');
        $live = ContentArticle::published()->firstOrFail();
        $this->assertSame('Synthetic original article body.', $live->body);
        $this->assertArrayNotHasKey('draft_payload', $live->toArray());
        $this->getJson($path)->assertOk()->assertJsonPath('data.draft.body', 'Unpublished draft body.');
        $patient = User::factory()->create();
        app('auth')->forgetGuards();
        $this->withToken($patient->createToken('fixture')->plainTextToken);
        $this->getJson('/api/v1/content/articles/synthetic-article')->assertOk()->assertJsonPath('data.body', 'Synthetic original article body.')->assertJsonMissingPath('data.draft_payload');
        $this->admin();
        $this->putJson($path, [...$this->draft(), 'expected_version' => $published['version']])->assertConflict();
        $revised = $this->postJson($path.'/publish', ['expected_version' => $edited['version'], 'approved' => true])->assertOk()->json('data');
        $this->assertSame('Unpublished draft body.', ContentArticle::published()->firstOrFail()->body);
        $this->putJson($path, [...$this->draft(), 'slug' => 'different-key', 'expected_version' => $revised['version']])->assertUnprocessable();
        $this->deleteJson($path, ['expected_version' => $revised['version']])->assertOk()->assertJsonPath('data.published', false);
        $this->assertSame(0, ContentArticle::published()->count());
        $this->assertDatabaseHas('admin_audit_logs', ['resource' => 'articles', 'action' => 'publish']);
        $this->assertDatabaseHas('content_articles', ['id' => $record['id']]);
    }
}
