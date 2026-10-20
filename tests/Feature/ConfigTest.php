<?php
use App\Models\FeatureFlag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('provides public configuration with safe defaults and no crisis switches', function () {
    $response = $this->getJson('/api/v1/config')->assertOk()->assertJsonPath('flags.ai_assistant', false)
        ->assertJsonPath('flags.community', false)->assertJsonPath('flags.analytics', false);
    expect(array_keys($response->json('flags')))->not->toContain('crisis', 'safety_plan', 'breathing', 'grounding');
});

it('caches configuration for sixty seconds', function () {
    $this->getJson('/api/v1/config')->assertOk();
    DB::table('feature_flags')->where('key', 'community')->update(['enabled' => true]);
    $this->getJson('/api/v1/config')->assertJsonPath('flags.community', false);
    $this->travel(61)->seconds();
    $this->getJson('/api/v1/config')->assertJsonPath('flags.community', true);
});

it('invalidates cached configuration when a flag changes', function () {
    $this->getJson('/api/v1/config')->assertOk();
    FeatureFlag::where('key', 'community')->first()->update(['enabled' => true]);
    $this->getJson('/api/v1/config')->assertJsonPath('flags.community', true);
});

it('keeps AI disabled even when a persisted flag enables it', function () {
    FeatureFlag::where('key', 'ai_assistant')->first()->update(['enabled' => true]);
    $this->getJson('/api/v1/config')->assertJsonPath('flags.ai_assistant', false);
});

it('allows toggling only registered flags', function () {
    $this->artisan('flags:set', ['key' => 'ai_assistant', 'enabled' => 'false'])->assertSuccessful();
    $this->getJson('/api/v1/config')->assertJsonPath('flags.ai_assistant', false);
    $this->artisan('flags:set', ['key' => 'crisis', 'enabled' => 'false'])->assertFailed();
});
