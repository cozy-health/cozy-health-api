<?php

namespace Tests\Feature;

use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    public function test_security_headers_cover_success_auth_failure_and_validation_errors(): void
    {
        foreach ([$this->getJson('/api/v1/me'), $this->postJson('/api/v1/auth/login', []), $this->getJson('/api/v1/not-found')] as $response) {
            $response->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains')
                ->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('X-Frame-Options', 'DENY')
                ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
                ->assertHeader('Content-Security-Policy', "default-src 'self'")
                ->assertHeader('Permissions-Policy', 'geolocation=(), microphone=(), camera=()');
        }
    }
}
