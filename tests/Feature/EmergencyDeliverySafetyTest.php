<?php

use App\Jobs\SendEmergencyContactAlert;
use App\Models\EmergencyEmailContact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function isolatedEmergencyDelivery(): array
{
    config(['launch.emergency_email.enabled' => true]);
    $user = User::factory()->create();
    $token = Str::random(64);
    $stop = Str::random(64);
    $contact = EmergencyEmailContact::create([
        'id' => (string) Str::uuid(), 'user_id' => $user->id,
        'email' => 'synthetic-contact@example.test',
        'token_hash' => hash('sha256', $token),
        'unsubscribe_hash' => hash('sha256', $stop), 'unsubscribe_token' => $stop,
        'token_expires_at' => now()->addHour(), 'confirmed_at' => now(),
    ]);
    $id = (string) Str::uuid();
    DB::table('emergency_email_alerts')->insert([
        'id' => $id, 'user_id' => $user->id, 'contact_id' => $contact->id,
        'request_id' => (string) Str::uuid(), 'status' => 'queued',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    return [$user, $contact, $id, $token, $stop];
}

it('cancels queued emergency mail after the recipient unsubscribes', function () {
    Mail::fake();
    [$user, $contact, $id, $token, $stop] = isolatedEmergencyDelivery();
    $this->postJson('/api/v1/emergency-email/unsubscribe', ['id' => $contact->id, 'token' => $stop])->assertOk();
    (new SendEmergencyContactAlert($id))->handle();
    Mail::assertNothingSent();
    $this->assertDatabaseMissing('emergency_email_contacts', ['id' => $contact->id]);
    $this->assertDatabaseMissing('emergency_email_alerts', ['id' => $id]);
});

it('cannot send queued emergency mail after account deletion', function () {
    Mail::fake();
    [$user, $contact, $id] = isolatedEmergencyDelivery();
    $user->forceDelete();
    (new SendEmergencyContactAlert($id))->handle();
    Mail::assertNothingSent();
    $this->assertDatabaseMissing('emergency_email_contacts', ['id' => $contact->id]);
    $this->assertDatabaseMissing('emergency_email_alerts', ['id' => $id]);
});

it('fails queued emergency mail safely when the feature is withdrawn', function () {
    Mail::fake();
    [$user, $contact, $id] = isolatedEmergencyDelivery();
    config(['launch.emergency_email.enabled' => false]);
    $job = new SendEmergencyContactAlert($id); $job->handle(); $job->handle();
    Mail::assertNothingSent();
    $this->assertDatabaseHas('emergency_email_alerts', ['id' => $id, 'status' => 'failed']);
});

it('does not send a stale emergency request after a delayed worker restart', function () {
    Mail::fake();
    [$user, $contact, $id] = isolatedEmergencyDelivery();
    DB::table('emergency_email_alerts')->where('id', $id)->update(['created_at' => now()->subMinutes(11)]);
    (new SendEmergencyContactAlert($id))->handle();
    Mail::assertNothingSent();
    $this->assertDatabaseHas('emergency_email_alerts', ['id' => $id, 'status' => 'failed']);
});

it('requires recipient confirmation again before queued emergency mail can send', function () {
    Mail::fake();
    [$user, $contact, $id] = isolatedEmergencyDelivery();
    $contact->update(['confirmed_at' => null]);
    (new SendEmergencyContactAlert($id))->handle();
    Mail::assertNothingSent();
    $this->assertDatabaseHas('emergency_email_alerts', ['id' => $id, 'status' => 'failed']);
});

it('does not replay an emergency send after the mail transport throws', function () {
    [$user, $contact, $id] = isolatedEmergencyDelivery();
    Mail::shouldReceive('to')->once()->with($contact->email)->andThrow(new RuntimeException('synthetic transport timeout'));
    $job = new SendEmergencyContactAlert($id); $job->handle(); $job->handle();
    $this->assertDatabaseHas('emergency_email_alerts', ['id' => $id, 'status' => 'failed']);
});

it('rejects expired confirmation and forged unsubscribe tokens without changing the recipient', function () {
    Mail::fake();
    [$user, $contact, $id, $token] = isolatedEmergencyDelivery();
    $contact->update(['confirmed_at' => null, 'token_expires_at' => now()->subSecond()]);
    $this->postJson('/api/v1/emergency-email/confirm', ['id' => $contact->id, 'token' => $token])->assertStatus(410);
    $this->postJson('/api/v1/emergency-email/unsubscribe', ['id' => $contact->id, 'token' => str_repeat('x', 64)])->assertNotFound();
    expect($contact->fresh()->confirmed_at)->toBeNull();
    Mail::assertNothingSent();
});
