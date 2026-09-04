<?php

namespace Tests\Feature;

use App\Models\Provider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProviderMonitoringIntervalTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_choose_a_supported_interval_without_resetting_connection_health(): void
    {
        [$owner, $provider] = $this->provider();
        $checkedAt = now()->subMinute();
        $provider->forceFill([
            'connection_status' => Provider::CONNECTION_HEALTHY,
            'connection_checked_at' => $checkedAt,
        ])->save();

        $this->actingAs($owner)->get(route('providers.edit', $provider))
            ->assertSuccessful()
            ->assertSee('Automatic check interval')
            ->assertSee('Every 1 hour')
            ->assertSee('Every 6 hours')
            ->assertSee('Every 12 hours')
            ->assertSee('Every 24 hours');

        $this->patch(route('providers.update', $provider), [
            ...$this->payload($provider),
            'connection_check_interval_minutes' => '360',
        ])->assertRedirect(route('providers.show', $provider));

        $provider->refresh();
        $this->assertSame(360, $provider->connection_check_interval_minutes);
        $this->assertSame(Provider::CONNECTION_HEALTHY, $provider->connection_status);
        $this->assertSame($checkedAt->timestamp, $provider->connection_checked_at->timestamp);
        $this->get(route('providers.show', $provider))
            ->assertSuccessful()
            ->assertSee('every 6 hours');
        $this->get(route('providers.index'))
            ->assertSuccessful()
            ->assertSee('Every 6 hours');
    }

    public function test_interval_is_restricted_to_supported_values(): void
    {
        [$owner, $provider] = $this->provider();

        foreach ([0, 59, 61, 2880, 'six hours'] as $interval) {
            $this->actingAs($owner)->patch(route('providers.update', $provider), [
                ...$this->payload($provider),
                'connection_check_interval_minutes' => $interval,
            ])->assertSessionHasErrors('connection_check_interval_minutes');
        }

        $this->assertSame(
            Provider::DEFAULT_CONNECTION_CHECK_INTERVAL_MINUTES,
            $provider->fresh()->connection_check_interval_minutes,
        );
    }

    public function test_operator_configuration_selects_a_supported_default_for_new_providers(): void
    {
        $owner = User::factory()->create();
        config(['lessbuild.provider_health_interval_minutes' => 360]);

        $this->actingAs($owner)->post(route('providers.store'), [
            'name' => 'Configured GitHub',
            'provider' => Provider::TYPE_GITHUB,
            'token' => 'provider-secret',
            'description' => 'Provider',
            'connection_monitoring_enabled' => '1',
        ])->assertRedirect();

        $this->assertDatabaseHas('providers', [
            'user_id' => $owner->id,
            'name' => 'Configured GitHub',
            'connection_check_interval_minutes' => 360,
        ]);

        config(['lessbuild.provider_health_interval_minutes' => 61]);
        $this->post(route('providers.store'), [
            'name' => 'Fallback GitHub',
            'provider' => Provider::TYPE_GITHUB,
            'token' => 'provider-secret',
            'description' => 'Provider',
            'connection_monitoring_enabled' => '1',
        ])->assertRedirect();

        $this->assertDatabaseHas('providers', [
            'user_id' => $owner->id,
            'name' => 'Fallback GitHub',
            'connection_check_interval_minutes' => Provider::DEFAULT_CONNECTION_CHECK_INTERVAL_MINUTES,
        ]);
    }

    /** @return array{User, Provider} */
    private function provider(): array
    {
        $owner = User::factory()->create();
        $provider = $owner->providers()->create([
            'name' => 'GitHub',
            'provider' => Provider::TYPE_GITHUB,
            'token' => 'provider-secret',
            'description' => 'Provider',
        ]);

        return [$owner, $provider];
    }

    /** @return array<string, mixed> */
    private function payload(Provider $provider): array
    {
        return [
            'name' => $provider->name,
            'provider' => $provider->provider,
            'description' => $provider->description,
            'connection_monitoring_enabled' => '1',
        ];
    }
}
