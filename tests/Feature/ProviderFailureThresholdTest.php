<?php

namespace Tests\Feature;

use App\Models\Provider;
use App\Models\ProviderConnectionCheck;
use App\Models\User;
use App\Services\ProviderHealthMonitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProviderFailureThresholdTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_choose_a_supported_threshold_without_resetting_health(): void
    {
        [$owner, $provider] = $this->provider();
        $checkedAt = now()->subMinute();
        $provider->forceFill([
            'connection_status' => Provider::CONNECTION_HEALTHY,
            'connection_checked_at' => $checkedAt,
            'connection_failure_threshold' => 3,
            'connection_failure_count' => 2,
        ])->save();

        $this->actingAs($owner)->get(route('providers.edit', $provider))
            ->assertSuccessful()
            ->assertSee('Failure confirmation')
            ->assertSee('After 1 consecutive failure')
            ->assertSee('After 2 consecutive failures')
            ->assertSee('After 3 consecutive failures')
            ->assertSee('After 5 consecutive failures');

        $this->patch(route('providers.update', $provider), [
            ...$this->payload($provider),
            'connection_failure_threshold' => '5',
        ])->assertRedirect(route('providers.show', $provider));

        $provider->refresh();
        $this->assertSame(5, $provider->connection_failure_threshold);
        $this->assertSame(2, $provider->connection_failure_count);
        $this->assertSame(Provider::CONNECTION_HEALTHY, $provider->connection_status);
        $this->assertSame($checkedAt->timestamp, $provider->connection_checked_at->timestamp);
        $this->get(route('providers.show', $provider))
            ->assertSuccessful()
            ->assertSee('after 5 consecutive failures')
            ->assertSee('2 failures recorded');
        $this->get(route('providers.index'))
            ->assertSuccessful()
            ->assertSee('Alert after 5 failures')
            ->assertSee('2 recorded');
    }

    public function test_threshold_is_restricted_to_supported_values(): void
    {
        [$owner, $provider] = $this->provider();

        foreach ([0, 4, 6, 10, 'three failures'] as $threshold) {
            $this->actingAs($owner)->patch(route('providers.update', $provider), [
                ...$this->payload($provider),
                'connection_failure_threshold' => $threshold,
            ])->assertSessionHasErrors('connection_failure_threshold');
        }

        $this->assertSame(
            Provider::DEFAULT_CONNECTION_FAILURE_THRESHOLD,
            $provider->fresh()->connection_failure_threshold,
        );
    }

    public function test_operator_configuration_selects_a_supported_default_for_new_providers(): void
    {
        $owner = User::factory()->create();
        config(['lessbuild.provider_health_failure_threshold' => 3]);

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
            'connection_failure_threshold' => 3,
        ]);

        config(['lessbuild.provider_health_failure_threshold' => 4]);
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
            'connection_failure_threshold' => Provider::DEFAULT_CONNECTION_FAILURE_THRESHOLD,
        ]);
    }

    public function test_manual_failures_are_recorded_immediately_and_use_the_same_threshold(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://api.github.com/user' => Http::response(['message' => 'expired'], 401)]);
        [$owner, $provider] = $this->provider();
        $provider->update(['connection_failure_threshold' => 2]);

        $this->actingAs($owner)->post(route('providers.connection.test', $provider))
            ->assertSessionHas('provider_connection.successful', false);
        $provider->refresh();
        $this->assertNull($provider->connection_status);
        $this->assertSame(1, $provider->connection_failure_count);
        $this->assertSame(0, $owner->notifications()->count());
        $this->assertDatabaseHas('provider_connection_checks', [
            'provider_id' => $provider->id,
            'successful' => false,
            'source' => ProviderConnectionCheck::SOURCE_MANUAL,
        ]);
        $this->actingAs($owner)->get(route('providers.show', $provider))
            ->assertSuccessful()
            ->assertSee('Confirmed connection status:')
            ->assertSee('Unchecked')
            ->assertSee('1 failure recorded')
            ->assertSee('Connection failed. GitHub returned HTTP 401.');

        $this->actingAs($owner)->post(route('providers.connection.test', $provider))
            ->assertSessionHas('provider_connection.successful', false);
        $provider->refresh();
        $this->assertSame(Provider::CONNECTION_FAILED, $provider->connection_status);
        $this->assertSame(2, $provider->connection_failure_count);
        $this->assertSame(1, $owner->notifications()->count());
        $this->assertSame(2, $provider->connectionChecks()->count());
    }

    public function test_result_started_before_a_threshold_change_is_discarded(): void
    {
        [$owner, $provider] = $this->provider();
        Http::fake(function () use ($provider) {
            $provider->update(['connection_failure_threshold' => 3]);

            return Http::response(['message' => 'expired'], 401);
        });

        $result = app(ProviderHealthMonitor::class)->check($provider, automatic: true);

        $this->assertFalse($result['recorded']);
        $provider->refresh();
        $this->assertSame(3, $provider->connection_failure_threshold);
        $this->assertSame(0, $provider->connection_failure_count);
        $this->assertNull($provider->connection_status);
        $this->assertNull($provider->connection_checked_at);
        $this->assertSame(0, $provider->connectionChecks()->count());
        $this->assertSame(0, $owner->notifications()->count());
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
