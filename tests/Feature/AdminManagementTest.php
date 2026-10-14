<?php
namespace Tests\Feature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Mail};
use Illuminate\Support\Str;
use Tests\TestCase;
class AdminManagementTest extends TestCase
{
    use RefreshDatabase;
    private function admin(array $abilities = ['admin:metrics', 'admin:users', 'admin:content', 'admin:moderation', 'admin:subscriptions', 'admin:email']): User
    {
        $admin = User::factory()->create(); $admin->forceFill(['role' => 'admin'])->save();
        app('auth')->forgetGuards();
        $this->withToken($admin->createToken('cozy-admin-management-v1', $abilities, now()->addMinutes(30))->plainTextToken);
        return $admin;
    }
    private function definition(): array
    {
        return ['title' => 'Wellbeing', 'slug' => 'wellbeing', 'type' => 'wellness', 'description' => 'Reflection', 'estimated_minutes' => 2,
            'disclaimer' => 'Not a diagnosis', 'scoring_rules' => ['timeframe' => 'Today', 'bands' => [['max' => 1, 'label' => 'Reflection', 'recommendation' => 'Rest']]],
            'questions' => [['question' => 'Support today?', 'options' => [['label' => 'Some', 'score' => 0], ['label' => 'More', 'score' => 1]]]]];
    }
    public function test_management_login_identity_and_legacy_token_boundaries(): void
    {
        $admin = $this->admin(); $admin->password = 'Fixture7!Strong'; $admin->save();
        $response = $this->postJson('/api/v1/admin/auth/login', ['email' => $admin->email, 'password' => 'Fixture7!Strong', 'session_mode' => 'management-v1'])->assertOk();
        app('auth')->forgetGuards();
        $this->withToken($response->json('token'))->getJson('/api/v1/admin/auth/session')->assertOk()->assertJsonPath('name', $admin->name);
        $this->getJson('/api/v1/admin/users')->assertOk();
        $this->getJson('/api/v1/me')->assertForbidden();
        $this->putJson('/api/v1/admin/settings/test', ['value' => true])->assertForbidden();
        app('auth')->forgetGuards();
        $this->withToken($admin->createToken('cozy-admin-dashboard', ['admin:metrics'], now()->addMinutes(30))->plainTextToken)->getJson('/api/v1/admin/users')->assertForbidden();
    }
    public function test_capabilities_and_catalog_archival_preserve_history(): void
    {
        $this->admin(['admin:content']);
        $this->getJson('/api/v1/admin/users')->assertForbidden();
        $this->getJson('/api/v1/admin/content/packages')->assertForbidden();
        $body = ['name' => 'Walking', 'slug' => 'walking', 'description' => 'A gentle walk', 'sort_order' => 1, 'is_active' => true];
        $id = $this->postJson('/api/v1/admin/content/coping', $body)->assertCreated()->json('data.id');
        $this->putJson('/api/v1/admin/content/coping/'.$id, [...$body, 'name' => 'Gentle walking'])->assertOk();
        $this->assertDatabaseHas('wellness_catalog_aliases', ['alias' => 'walking', 'slug' => 'walking']);
        $this->putJson('/api/v1/admin/content/coping/'.$id, [...$body, 'slug' => 'another'])->assertUnprocessable();
        $this->deleteJson('/api/v1/admin/content/coping/'.$id)->assertOk();
        $this->assertDatabaseHas('coping_mechanisms', ['id' => $id, 'is_active' => false]);
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'archive', 'resource' => 'coping']);
    }
    public function test_users_edit_and_revocation_do_not_allow_admin_mutation(): void
    {
        $admin = $this->admin(); $patient = User::factory()->create();
        $patient->createToken('mobile');
        $this->putJson('/api/v1/admin/users/'.$patient->id, ['name' => 'Updated', 'email' => $patient->email, 'is_active' => false])->assertOk();
        $this->assertSame(0, $patient->tokens()->count());
        $this->putJson('/api/v1/admin/users/'.$admin->id, ['name' => 'Changed', 'email' => $admin->email, 'is_active' => false])->assertNotFound();
        $this->deleteJson('/api/v1/admin/users/'.$patient->id, ['confirmation' => 'NO'])->assertUnprocessable();
        Mail::fake();
        $this->deleteJson('/api/v1/admin/users/'.$patient->id, ['confirmation' => 'DELETE'])->assertOk();
        $this->assertDatabaseMissing('users', ['id' => $patient->id]);
        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }
    public function test_quiz_drafts_publish_and_revisions_preserve_live_definition(): void
    {
        $this->admin(); $definition = $this->definition();
        $id = $this->postJson('/api/v1/admin/quizzes', ['title' => 'Wellbeing', 'definition' => $definition])->assertCreated()->json('data.id');
        $this->assertDatabaseCount('quizzes', 0);
        $version = $this->postJson('/api/v1/admin/quizzes/'.$id.'/publish')->assertOk()->json('data.definition_version');
        $old = DB::table('quiz_definitions')->where('id', $version)->value('definition');
        $definition['questions'][0]['question'] = 'A revised question?';
        $this->putJson('/api/v1/admin/quizzes/'.$id, ['title' => 'Wellbeing', 'definition' => $definition])->assertOk();
        $this->assertSame('Support today?', DB::table('quiz_questions')->value('question'));
        $newVersion = $this->postJson('/api/v1/admin/quizzes/'.$id.'/publish')->assertOk()->json('data.definition_version');
        $this->assertNotSame($version, $newVersion);
        $this->assertSame($old, DB::table('quiz_definitions')->where('id', $version)->value('definition'));
        $this->deleteJson('/api/v1/admin/quizzes/'.$id)->assertOk();
        $this->assertDatabaseHas('quizzes', ['slug' => 'wellbeing', 'is_active' => false]);
    }
    public function test_invalid_quiz_scoring_returns_validation_instead_of_server_failure(): void
    {
        $this->admin(); $definition = $this->definition(); $definition['scoring_rules']['bands'][0]['max'] = 0;
        $this->postJson('/api/v1/admin/quizzes', ['title' => 'Wellbeing', 'definition' => $definition])->assertUnprocessable()->assertJsonValidationErrors('definition');
        $definition['questions'][0]['options'][0]['score'] = -1;
        $this->postJson('/api/v1/admin/quizzes', ['title' => 'Wellbeing', 'definition' => $definition])->assertUnprocessable();
    }
    public function test_challenge_catalog_enrollment_and_idempotent_completion(): void
    {
        $this->admin();
        $body = ['name' => 'Rest', 'slug' => 'rest', 'description' => 'Rest each day', 'duration_days' => 2, 'tasks' => ['Take a pause', 'Walk gently'], 'is_active' => true, 'sort_order' => 0];
        $id = $this->postJson('/api/v1/admin/content/challenges', $body)->assertCreated()->json('data.id');
        $patient = User::factory()->create(); app('auth')->forgetGuards(); $this->withToken($patient->createToken('mobile')->plainTextToken);
        $this->getJson('/api/v1/wellness/catalog')->assertOk()->assertJsonCount(9, 'data.moods');
        $this->postJson('/api/v1/wellness/challenges/'.$id.'/enroll')->assertOk();
        $this->postJson('/api/v1/wellness/challenges/'.$id.'/enroll')->assertOk();
        $this->postJson('/api/v1/wellness/challenges/'.$id.'/complete', ['day' => 1])->assertOk();
        $this->postJson('/api/v1/wellness/challenges/'.$id.'/complete', ['day' => 1])->assertOk();
        $this->postJson('/api/v1/wellness/challenges/'.$id.'/complete', ['day' => 2])->assertUnprocessable();
        $this->assertDatabaseCount('challenge_completions', 1);
    }
    public function test_email_previews_use_fixtures_and_queue_tests_are_audited(): void
    {
        Mail::fake(); $this->admin();
        foreach (array_keys(\App\Services\AdminEmailRegistry::EVENTS) as $event) $this->getJson('/api/v1/admin/emails/'.$event.'/preview')->assertOk()->assertJsonStructure(['data' => ['html']]);
        $this->postJson('/api/v1/admin/emails/test', ['email' => 'preview@example.test'])->assertStatus(202);
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'test-send']);
        $this->getJson('/api/v1/admin/emails')->assertOk();
    }
    public function test_insights_freeze_choices_deduplicate_and_exclude_free_text(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 14)); $this->admin();
        foreach (range(1, 5) as $_) {
            $user = User::factory()->create(['created_at' => '2026-06-01']);
            foreach (['2026-09-29', '2026-09-15', '2026-08-15'] as $date) DB::table('mood_entries')->insert(['id' => (string) Str::uuid(), 'user_id' => $user->id, 'mood' => 'good', 'intensity' => 5,
                'triggers' => json_encode(['Work', 'work', 'PRIVATE UNKNOWN']), 'coping_strategies' => json_encode(['Box breathing']), 'coping_helped' => 'yes',
                'custom_trigger' => 'PRIVATE CUSTOM', 'note' => 'PRIVATE NOTE', 'created_at' => $date.' 12:00:00', 'updated_at' => $date.' 12:00:00']);
        }
        $response = $this->getJson('/api/v1/admin/metrics/insights?range=30d')->assertOk()->assertJsonPath('data.triggers.0.count', 10)->assertJsonPath('data.coping.0.label', 'Box breathing');
        foreach (['PRIVATE', 'user_id', 'note', 'email'] as $secret) $this->assertStringNotContainsString($secret, $response->getContent());
        DB::table('mood_entries')->delete();
        $this->assertSame($response->json(), $this->getJson('/api/v1/admin/metrics/insights?range=30d')->json());
        $this->getJson('/api/v1/admin/metrics/insights?user_id=1')->assertUnprocessable();
    }
    public function test_small_groups_are_hidden_even_with_many_records(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 14)); $this->admin();
        $user = User::factory()->create();
        foreach (range(1, 20) as $_) DB::table('mood_entries')->insert(['id' => (string) Str::uuid(), 'user_id' => $user->id, 'mood' => 'anxious', 'intensity' => 4, 'created_at' => '2026-09-29 12:00:00', 'updated_at' => '2026-09-29 12:00:00']);
        $this->getJson('/api/v1/admin/metrics/insights')->assertOk()->assertJsonCount(0, 'data.moods')->assertJsonCount(0, 'data.anxiety')->assertJsonPath('data.summary.active_users.count', '<5');
    }
    public function test_anonymous_moderation_keeps_authors_private_and_changes_visibility(): void
    {
        $this->admin(); $author = User::factory()->create(); $id = (string) Str::uuid();
        DB::table('community_posts')->insert(['id' => $id, 'user_id' => $author->id, 'body' => 'Reported content', 'status' => 'pending', 'is_anonymous' => true,
            'guidelines_version' => 'v1', 'guidelines_accepted_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $report = DB::table('community_reports')->insertGetId(['reporter_id' => null, 'target_type' => 'post', 'target_id' => $id, 'reason' => 'safety',
            'snapshot' => json_encode(['user_id' => $author->id]), 'status' => 'pending', 'due_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
        $response = $this->getJson('/api/v1/admin/reports/'.$report.'/target')->assertOk()->assertJsonPath('data.is_anonymous', 1);
        $this->assertStringNotContainsString('user_id', $response->getContent());
        $this->getJson('/api/v1/admin/community/posts')->assertOk()->assertJsonMissingPath('data.data.0.user_id');
        $this->putJson('/api/v1/admin/reports/'.$report, ['decision' => 'publish'])->assertOk();
        $this->assertDatabaseHas('community_posts', ['id' => $id, 'status' => 'published']);
        $this->putJson('/api/v1/admin/reports/'.$report, ['decision' => 'remove'])->assertStatus(409);
    }
    public function test_provider_delivery_events_correlate_and_deduplicate_without_content(): void
    {
        config(['services.brevo.webhook_token' => str_repeat('t', 32)]);
        $id = (string) Str::uuid(); $key = \App\Services\EmailDeliveryTracking::key('provider-message');
        DB::table('email_delivery_logs')->insert(['id' => $id, 'event' => 'welcome', 'status' => 'sent', 'message_key' => $key, 'created_at' => now(), 'updated_at' => now()]);
        $payload = ['event' => 'delivered', 'email' => 'recipient@example.test', 'message-id' => 'provider-message', 'date' => now()->toIso8601String()];
        foreach ([1, 2] as $_) $this->withToken(str_repeat('t', 32))->postJson('/webhooks/brevo', $payload)->assertOk();
        $this->assertDatabaseCount('email_delivery_logs', 2);
        $this->assertDatabaseHas('email_delivery_logs', ['id' => $id, 'status' => 'delivered']);
        $this->assertStringNotContainsString('recipient@example.test', json_encode(DB::table('email_delivery_logs')->get()));
    }
    public function test_mailable_tracking_headers_survive_serialization_and_old_jobs(): void
    {
        $mail = new \App\Mail\VerifyEmailMail('000000'); $id = $mail->deliveryId;
        $restored = unserialize(serialize($mail));
        $this->assertSame($id, $restored->headers()->text['X-Cozy-Delivery']);
        unset($restored->deliveryId);
        $this->assertTrue(Str::isUuid($restored->headers()->text['X-Cozy-Delivery']));
    }
}
