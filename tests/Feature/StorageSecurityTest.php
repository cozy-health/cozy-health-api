<?php

namespace Tests\Feature;

use App\Mail\PasswordResetRequestMail;
use App\Mail\ProviderPasswordResetMail;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class StorageSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_consumer_reset_code_field_contains_a_password_hash(): void
    {
        Mail::fake();
        $user = User::factory()->create();
        $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertOk();
        $record = DB::table('password_reset_tokens')->where('email', $user->email)->first();
        Mail::assertQueued(PasswordResetRequestMail::class, function ($mail) use ($record) {
            $this->assertTrue(Hash::check($mail->token, $record->token));
            $this->assertNotSame($mail->token, $record->token);
            $this->assertNotSame('unknown', password_get_info($record->token)['algoName']);
            return true;
        });
    }

    public function test_consumer_reset_mail_code_is_encrypted_in_queue_and_decrypts_for_worker(): void
    {
        $user = User::factory()->create();
        $this->assertEncryptedMail(new PasswordResetRequestMail($user, '123456'));
    }

    public function test_provider_reset_mail_code_is_encrypted_in_queue_and_decrypts_for_worker(): void
    {
        $provider = Provider::create([
            'name' => 'Fixture provider', 'email' => 'provider@example.test',
            'password_hash' => 'Fixture7!Strong', 'is_active' => true,
        ]);
        $this->assertEncryptedMail(new ProviderPasswordResetMail($provider, '123456'));
    }

    private function assertEncryptedMail($mail): void
    {
        Queue::connection('database')->push(new SendQueuedMailable($mail), '', 'email');
        $payload = json_decode(DB::table('jobs')->value('payload'), true);
        $command = $payload['data']['command'];
        $this->assertStringNotContainsString('123456', $command);
        $this->assertStringNotContainsString($mail->user->email, $command);
        $job = unserialize(Crypt::decrypt($command));
        $this->assertInstanceOf(SendQueuedMailable::class, $job);
        $this->assertSame('123456', $job->mailable->token);
        $this->assertSame($mail->user->id, $job->mailable->user->id);
    }

    public function test_sanctum_token_is_sha256_hashed_at_rest(): void
    {
        $token = User::factory()->create()->createToken('storage-security');
        $plain = explode('|', $token->plainTextToken, 2)[1];
        $this->assertSame(hash('sha256', $plain), $token->accessToken->getRawOriginal('token'));
        $this->assertNotSame($plain, $token->accessToken->getRawOriginal('token'));
    }

    public function test_apple_refresh_token_is_encrypted_and_hidden(): void
    {
        $user = User::factory()->create(['apple_refresh_token' => 'fixture-apple-refresh']);
        $this->assertNotSame('fixture-apple-refresh', $user->getRawOriginal('apple_refresh_token'));
        $this->assertSame('fixture-apple-refresh', $user->fresh()->apple_refresh_token);
        $this->assertArrayNotHasKey('apple_refresh_token', $user->toArray());
    }
}
