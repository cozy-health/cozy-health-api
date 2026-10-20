<?php

namespace Tests\Feature;

use App\Services\Provider\EncryptedReportStorage;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CryptoSecurityTest extends TestCase
{
    public function test_pdf_ciphertext_round_trips_without_plaintext_and_detects_tampering(): void
    {
        $storage = new EncryptedReportStorage;
        $pdf = "%PDF-1.7\nSensitive clinical summary";
        $bytes = $storage->encrypt($pdf);
        $this->assertSame('AES-256-CBC', config('app.cipher'));
        $this->assertSame(32, strlen(base64_decode(substr(config('app.key'), 7))));
        $this->assertStringNotContainsString($pdf, $bytes);
        $this->assertSame($pdf, $storage->decrypt($bytes));
        $this->assertNotSame($bytes, $storage->encrypt($pdf));
        $payload = json_decode(base64_decode(substr($bytes, strlen(EncryptedReportStorage::PREFIX))), true);
        $payload['mac'] = str_repeat('0', 64);
        $this->expectException(\Illuminate\Contracts\Encryption\DecryptException::class);
        $storage->decrypt(EncryptedReportStorage::PREFIX.base64_encode(json_encode($payload)));
    }

    public function test_pdf_legacy_migration_preserves_bytes_is_idempotent_and_fails_closed(): void
    {
        Storage::fake('local');
        $disk = Storage::disk('local');
        $path = 'provider-reports/1/legacy.pdf';
        $pdf = "%PDF-1.7\nExisting clinical report";
        $disk->put($path, $pdf);
        $storage = new EncryptedReportStorage;
        $this->assertTrue($storage->migrate($path));
        $this->assertSame($pdf, $storage->decrypt($disk->get($path)));
        $this->assertFalse($storage->migrate($path));
        $this->assertFalse($disk->exists($path.'.encrypting'));
        $this->artisan('reports:encrypt')->expectsOutput('Migrated: 0; verified: 1; failed: 0.')->assertSuccessful();
        $this->expectException(\RuntimeException::class);
        $storage->decrypt($pdf);
    }

    public function test_invalid_report_migration_leaves_original_untouched(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('provider-reports/1/corrupt.pdf', 'not a PDF');
        $this->artisan('reports:encrypt')->assertFailed();
        $this->assertSame('not a PDF', Storage::disk('local')->get('provider-reports/1/corrupt.pdf'));
    }

    public function test_reports_reject_non_aes256_cipher(): void
    {
        config(['app.cipher' => 'AES-128-CBC']);
        $this->expectException(\RuntimeException::class);
        (new EncryptedReportStorage)->encrypt('%PDF-1.7');
    }

    public function test_http_is_rejected_before_auth_or_writes_but_health_probe_is_available(): void
    {
        config(['security.require_https' => true]);
        $this->postJson('http://localhost/api/v1/auth/login', [])->assertStatus(426);
        $this->postJson('https://localhost/api/v1/auth/login', [])->assertStatus(422);
        $this->get('http://localhost/up')->assertOk();
    }

    public function test_railway_forwarded_protocol_is_recognized_without_trusting_forwarded_host(): void
    {
        config(['security.require_https' => true]);
        $this->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'untrusted.test'])
            ->postJson('/api/v1/auth/login', [])->assertStatus(422);
        $this->withHeaders(['X-Forwarded-Proto' => 'http'])->postJson('/api/v1/auth/login', [])->assertStatus(426);
    }

    public function test_smtp_transport_requires_tls(): void
    {
        $transport = app('mail.manager')->mailer('smtp')->getSymfonyTransport();
        $this->assertTrue($transport->isTlsRequired());
    }

    public function test_mysql_tls_requirement_rejects_missing_trust_before_connecting(): void
    {
        $this->expectException(\RuntimeException::class);
        (new \App\Database\TlsMySqlConnector)->connect(['require_tls' => true, 'options' => []]);
    }

    public function test_mysql_tls_opt_out_disables_enforcement_and_ca_options_but_defaults_to_strict(): void
    {
        $keys = ['APP_ENV', 'DB_TLS_ENFORCE', 'DB_REQUIRE_TLS', 'MYSQL_SSL_CA'];
        $original = [];
        foreach ($keys as $key) {
            $original[$key] = [$_ENV[$key] ?? null, $_SERVER[$key] ?? null, getenv($key)];
        }
        try {
            $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'production';
            $_ENV['MYSQL_SSL_CA'] = $_SERVER['MYSQL_SSL_CA'] = '/missing/ca.pem';
            foreach (['DB_REQUIRE_TLS', 'DB_TLS_ENFORCE'] as $key) {
                unset($_ENV[$key], $_SERVER[$key]);
                putenv($key);
            }
            $strict = (require base_path('config/database.php'))['connections']['mysql'];
            $this->assertTrue($strict['require_tls']);
            $this->assertTrue($strict['options'][\PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT]);
            $_ENV['DB_TLS_ENFORCE'] = $_SERVER['DB_TLS_ENFORCE'] = 'false';
            $legacy = (require base_path('config/database.php'))['connections']['mysql'];
            $this->assertFalse($legacy['require_tls']);
            $this->assertArrayNotHasKey(\PDO::MYSQL_ATTR_SSL_CA, $legacy['options']);
            $_ENV['DB_TLS_ENFORCE'] = $_SERVER['DB_TLS_ENFORCE'] = 'true';
            $restored = (require base_path('config/database.php'))['connections']['mysql'];
            $this->assertTrue($restored['require_tls']);
        } finally {
            foreach ($original as $key => [$env, $server, $process]) {
                if ($env === null) { unset($_ENV[$key]); } else { $_ENV[$key] = $env; }
                if ($server === null) { unset($_SERVER[$key]); } else { $_SERVER[$key] = $server; }
                putenv($process === false ? $key : $key.'='.$process);
            }
        }
    }
}
