<?php

use App\Models\NextOfKinContact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function kinPayload(array $extra = []): array
{
    return ['name' => 'Trusted Person', 'phone' => '+234 803 123 4567', 'relationship' => 'Sibling', 'client_updated_at' => now()->toISOString(), ...$extra];
}

it('requires authentication for all next-of-kin endpoints', function () {
    $this->getJson('/api/v1/next-of-kin')->assertUnauthorized();
    $this->putJson('/api/v1/next-of-kin', kinPayload())->assertUnauthorized();
    $this->deleteJson('/api/v1/next-of-kin')->assertUnauthorized();
});

it('saves one encrypted contact and isolates accounts', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    Sanctum::actingAs($owner);
    $this->getJson('/api/v1/next-of-kin')->assertOk()->assertJsonPath('data', null);
    $this->putJson('/api/v1/next-of-kin', kinPayload(['user_id' => $other->id]))->assertOk()->assertJsonPath('data.contact.phone', '+2348031234567');
    expect(DB::table('next_of_kin_contacts')->value('contact'))->not->toContain('Trusted Person');
    $this->travel(1)->seconds();
    $this->putJson('/api/v1/next-of-kin', kinPayload(['name' => 'Updated']))->assertOk()->assertJsonPath('data.contact.name', 'Updated');
    expect(NextOfKinContact::count())->toBe(1);
    Sanctum::actingAs($other);
    $this->getJson('/api/v1/next-of-kin')->assertOk()->assertJsonPath('data', null);
    $this->deleteJson('/api/v1/next-of-kin')->assertOk();
    expect(NextOfKinContact::where('user_id', $owner->id)->first()->contact['name'])->toBe('Updated');
});

it('rejects invalid contact details and unreasonable timestamps', function () {
    Sanctum::actingAs(User::factory()->create());
    foreach ([['phone' => '08031234567'], ['name' => ' '], ['phone' => '+0123456789'], ['name' => str_repeat('a', 101)], ['client_updated_at' => now()->addDay()->toISOString()]] as $invalid) {
        $this->putJson('/api/v1/next-of-kin', kinPayload($invalid))->assertUnprocessable();
    }
    expect(NextOfKinContact::count())->toBe(0);
});

it('synchronizes contact changes and preserves removal against stale replay', function () {
    Sanctum::actingAs(User::factory()->create());
    $old = now()->subMinute()->toISOString();
    $sync = fn ($action, $data) => $this->postJson('/api/v1/sync/batch', ['operations' => [[
        'client_operation_id' => 'kin-op', 'id' => 'primary', 'type' => 'next_of_kin', 'action' => $action, 'data' => ['id' => 'primary', ...$data],
    ]]])->assertOk()->assertJsonPath('data.results.0.success', true);
    $sync('upsert', kinPayload(['client_updated_at' => $old]));
    $sync('delete', ['client_updated_at' => now()->toISOString()]);
    $sync('upsert', kinPayload(['client_updated_at' => $old]));
    $this->getJson('/api/v1/next-of-kin')->assertOk()->assertJsonPath('data.contact', null);
});

it('removes contact details when the account is deleted', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $this->putJson('/api/v1/next-of-kin', kinPayload())->assertOk();
    $user->delete();
    expect(NextOfKinContact::count())->toBe(0);
});
