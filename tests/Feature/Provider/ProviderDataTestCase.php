<?php

namespace Tests\Feature\Provider;

use App\Models\Provider;
use App\Models\ProviderClientLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

abstract class ProviderDataTestCase extends TestCase
{
    use RefreshDatabase;

    protected function provider(array $attributes = []): Provider
    {
        return Provider::create([...['name' => 'Dr. Test', 'email' => fake()->unique()->safeEmail(),
            'practice' => 'Test Practice', 'is_active' => true, 'is_verified' => true], ...$attributes]);
    }

    protected function link(Provider $provider, User $user, array $attributes = []): ProviderClientLink
    {
        return ProviderClientLink::create([...['provider_id' => $provider->id, 'provider_email' => $provider->email,
            'user_id' => $user->id, 'linked_at' => now(), 'verified_at' => now()], ...$attributes]);
    }

    protected function asProvider(Provider $provider): static
    {
        return $this->withToken($provider->createToken('test')->plainTextToken);
    }
}
