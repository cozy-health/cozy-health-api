<?php

namespace Tests\Feature;

use App\Models\Provider;
use App\Models\Setting;
use App\Models\User;
use App\Services\DocumentConsentService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DocumentConsentTest extends TestCase
{
    use RefreshDatabase;

    private function login(): User
    {
        $user = User::factory()->create();
        app('auth')->forgetGuards();
        $this->withToken($user->createToken('device')->plainTextToken);
        return $user;
    }

    private function draft(string $type = 'tos'): array
    {
        return ['document_type' => $type, 'version' => 'draft-0.1', 'accepted' => false, 'pre_acceptance' => true];
    }

    public function test_draft_tracking_is_not_legal_acceptance_and_is_idempotent(): void
    {
        $this->login();
        $this->postJson('/api/v1/me/consents', $this->draft())->assertOk()->assertJsonPath('data.status', 'pre_acceptance')->assertJsonPath('data.accepted_at', null);
        $this->postJson('/api/v1/me/consents', $this->draft())->assertOk();
        $this->assertDatabaseCount('user_consents', 1);
        $this->assertDatabaseCount('user_consent_events', 1);
        $this->postJson('/api/v1/me/consents', [...$this->draft(), 'accepted' => true, 'pre_acceptance' => false])->assertUnprocessable();
    }

    public function test_records_are_owned_and_stale_versions_or_accounts_are_rejected(): void
    {
        $first = $this->login();
        $this->postJson('/api/v1/me/consents', $this->draft())->assertOk();
        $second = $this->login();
        $this->getJson('/api/v1/me/consents')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('owner_id', $second->id);
        $this->postJson('/api/v1/me/consents', [...$this->draft(), 'version' => 'old'])->assertUnprocessable();
        $this->postJson('/api/v1/me/consents', [...$this->draft(), 'actor_id' => $first->id])->assertStatus(409);
    }

    public function test_marketing_requires_opt_in_and_withdrawal_is_audited(): void
    {
        $user = $this->login();
        $service = app(DocumentConsentService::class);
        $this->assertFalse($service->marketingAllowed($user));
        $documents = config('consent.documents');
        $documents['marketing'] = [...$documents['marketing'], 'version' => '1.0', 'published' => true, 'content' => 'Optional marketing news. Withdraw any time.'];
        app(SettingsService::class)->set('consent.documents', $documents);
        $this->postJson('/api/v1/me/consents', $this->draft('marketing'))->assertUnprocessable();
        $data = ['document_type' => 'marketing', 'version' => '1.0', 'accepted' => true];
        $this->postJson('/api/v1/me/consents', $data)->assertOk();
        $this->assertTrue($service->marketingAllowed($user));
        $this->postJson('/api/v1/me/consents', [...$data, 'accepted' => false])->assertOk()->assertJsonPath('data.status', 'withdrawn');
        $this->assertFalse($service->marketingAllowed($user));
        $this->assertDatabaseCount('user_consent_events', 2);
    }

    public function test_blocking_requires_published_documents_and_marketing_stays_optional(): void
    {
        app(SettingsService::class)->set('consent.enforce_blocking', true);
        $this->getJson('/api/v1/config')->assertJsonPath('consent.enforce_blocking', false);
        $documents = config('consent.documents');
        foreach ($documents as $type => &$doc) {
            if ($type !== 'marketing') $doc = [...$doc, 'version' => '1.0', 'published' => true, 'content' => 'Approved document text for this test.'];
        }
        unset($doc);
        app(SettingsService::class)->set('consent.documents', $documents);
        $this->getJson('/api/v1/config')->assertJsonPath('consent.enforce_blocking', true)->assertJsonPath('consent.documents.marketing.required', false);
        $this->login();
        $this->postJson('/api/v1/me/consents', ['document_type' => 'tos', 'version' => '1.0', 'accepted' => true])->assertOk()->assertJsonPath('data.status', 'accepted');
        $this->assertNotNull(DB::table('user_consents')->value('accepted_at'));
    }

    public function test_patient_consent_is_enforced_independently_of_age_and_recovers_after_acceptance(): void
    {
        config(['age.enabled' => false]);
        $documents = config('consent.documents');
        foreach ($documents as $type => &$doc) {
            if ($type !== 'marketing') $doc = [...$doc, 'version' => '1.0', 'published' => true, 'content' => 'Approved fixture text.'];
        }
        unset($doc);
        app(SettingsService::class)->set('consent.documents', $documents);
        app(SettingsService::class)->set('consent.enforce_blocking', true);
        $this->login();
        $this->getJson('/api/v1/mood-entries')->assertForbidden()->assertJsonPath('code', 'consent_required');
        $this->postJson('/api/v1/sync/batch', ['operations' => []])->assertForbidden()->assertJsonPath('code', 'consent_required');
        $this->getJson('/api/v1/me/consents')->assertOk();
        $this->getJson('/api/v1/user/profile')->assertOk()->assertJsonCount(3, 'data')->assertJsonMissingPath('data.theme');
        $this->getJson('/api/v1/me')->assertOk()->assertJsonCount(3, 'data');
        foreach ($documents as $type => $doc) {
            if ($type !== 'marketing' && $doc['required']) {
                $this->postJson('/api/v1/me/consents', ['document_type' => $type, 'version' => '1.0', 'accepted' => true])->assertOk();
            }
        }
        $this->getJson('/api/v1/mood-entries')->assertOk();
        $this->postJson('/api/v1/me/consents', ['document_type' => 'health_data', 'version' => '1.0', 'accepted' => false])->assertOk();
        $this->getJson('/api/v1/mood-entries')->assertForbidden()->assertJsonPath('code', 'consent_required');
    }

    public function test_provider_documents_have_separate_records_and_draft_config(): void
    {
        $provider = Provider::create(['name' => 'Provider', 'email' => 'provider@example.test', 'password_hash' => 'Fixture7!Strong']);
        $this->withToken($provider->createToken('device')->plainTextToken);
        $this->getJson('/api/v1/provider/consent-config')->assertOk()->assertJsonPath('consent.enforce_blocking', false);
        $this->postJson('/api/v1/provider/me/consents', $this->draft())->assertOk();
        $this->getJson('/api/v1/provider/me/consents')->assertJsonCount(1, 'data');
        $this->assertDatabaseCount('user_consents', 0);
        $this->assertDatabaseCount('provider_consents', 1);
    }

    public function test_anonymous_consent_access_is_rejected(): void
    {
        $this->getJson('/api/v1/me/consents')->assertUnauthorized();
        $this->postJson('/api/v1/me/consents', $this->draft())->assertUnauthorized();
    }

    public function test_operator_can_publish_via_json_command_and_cannot_change_published_text_in_place(): void
    {
        $documents = config('consent.provider_documents');
        $documents['tos'] = [...$documents['tos'], 'version' => '1.0', 'published' => true, 'content' => 'Approved provider fixture.'];
        $this->artisan('settings:set', ['key' => 'consent.provider_documents', 'value' => json_encode($documents)])->assertSuccessful();
        $documents['tos']['content'] = 'Different terms without a new version.';
        $this->artisan('settings:set', ['key' => 'consent.provider_documents', 'value' => json_encode($documents)])->assertFailed();
        $this->assertSame('Approved provider fixture.', Setting::find('consent.provider_documents')->value['tos']['content']);
        $this->artisan('settings:set', ['key' => 'consent.documents', 'value' => '{invalid'])->assertFailed();
    }
}
