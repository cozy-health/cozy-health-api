<?php

namespace Tests\Feature;

use Tests\TestCase;

class AppAssociationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Public OS association fetches must work without browser sessions or
        // a sessions table, including installations using database sessions.
        config(['session.driver' => 'database']);
    }

    public function test_unconfigured_associations_fail_closed_without_starting_sessions(): void
    {
        config(['quiz.android_fingerprints' => [], 'quiz.ios_app_id' => null]);
        foreach (['assetlinks.json', 'apple-app-site-association'] as $file) {
            $this->get('/.well-known/'.$file)->assertStatus(503)->assertCookieMissing(config('session.cookie'));
        }
    }

    public function test_android_association_exposes_only_the_configured_identity(): void
    {
        $fingerprint = implode(':', array_fill(0, 32, 'AA'));
        config(['quiz.android_package' => 'com.example.cozy_health', 'quiz.android_fingerprints' => [$fingerprint]]);
        $this->get('/.well-known/assetlinks.json')->assertOk()
            ->assertJsonPath('0.target.package_name', 'com.example.cozy_health')
            ->assertJsonPath('0.target.sha256_cert_fingerprints', [$fingerprint])
            ->assertCookieMissing(config('session.cookie'));
    }

    public function test_ios_association_exposes_the_configured_app_and_invitation_path(): void
    {
        config(['quiz.ios_app_id' => 'FIXTURETEAM.com.example.cozyHealth']);
        $this->get('/.well-known/apple-app-site-association')->assertOk()
            ->assertJsonPath('applinks.details.0.appID', 'FIXTURETEAM.com.example.cozyHealth')
            ->assertJsonPath('applinks.details.0.paths', ['/quiz-invite/*'])
            ->assertCookieMissing(config('session.cookie'));
    }
}
