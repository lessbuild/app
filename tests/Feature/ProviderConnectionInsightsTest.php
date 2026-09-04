<?php

namespace Tests\Feature;

use App\Models\Provider;
use App\Models\ProviderConnectionCheck;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProviderConnectionInsightsTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_sees_metrics_from_retained_observations(): void
    {
        [$owner, $provider] = $this->provider('Observed');
        $this->record($provider, true, 101, now()->subMinutes(5));
        $this->record($provider, true, 110, now()->subMinutes(4));
        $this->record($provider, true, 117, now()->subMinutes(3));
        $this->record($provider, false, 500, now()->subMinutes(2));
        $this->record($provider, false, 600, now()->subMinute());

        $this->actingAs($owner)->get(route('providers.show', $provider))
            ->assertSuccessful()
            ->assertViewHas('connectionMetrics', [
                'total' => 5,
                'successful' => 3,
                'success_rate' => 60,
                'median_successful_duration_ms' => 110,
                'failure_streak' => 2,
            ])
            ->assertSee('Retained checks')
            ->assertSee('Observed connection success')
            ->assertSee('60%')
            ->assertSee('3 successful checks')
            ->assertSee('Median successful response')
            ->assertSee('110 ms')
            ->assertSee('Failed timings are excluded.')
            ->assertSee('2 consecutive failed checks')
            ->assertSee('not an SLA or a guarantee that the credential is currently valid.');
    }

    public function test_empty_history_has_explicit_unknown_metrics(): void
    {
        [$owner, $provider] = $this->provider('Empty');

        $this->actingAs($owner)->get(route('providers.show', $provider))
            ->assertSuccessful()
            ->assertViewHas('connectionMetrics', [
                'total' => 0,
                'successful' => 0,
                'success_rate' => null,
                'median_successful_duration_ms' => null,
                'failure_streak' => 0,
            ])
            ->assertSee('Not available')
            ->assertSee('Not recorded')
            ->assertSee('0 consecutive failed checks')
            ->assertSee('No connection checks have been recorded yet.');
    }

    public function test_metrics_use_only_the_newest_hundred_checks(): void
    {
        [$owner, $provider] = $this->provider('Bounded');
        $this->record($provider, true, 9999, now()->subMinutes(101));

        foreach (range(1, ProviderConnectionCheck::MAX_PER_PROVIDER) as $position) {
            $this->record(
                $provider,
                $position <= 98,
                $position <= 98 ? 100 : 800,
                now()->subMinutes(101 - $position),
            );
        }

        $this->actingAs($owner)->get(route('providers.show', $provider))
            ->assertSuccessful()
            ->assertViewHas('connectionMetrics', [
                'total' => ProviderConnectionCheck::MAX_PER_PROVIDER,
                'successful' => 98,
                'success_rate' => 98,
                'median_successful_duration_ms' => 100,
                'failure_streak' => 2,
            ]);
    }

    /** @return array{User, Provider} */
    private function provider(string $prefix): array
    {
        $owner = User::factory()->create();
        $provider = $owner->providers()->create([
            'name' => "{$prefix} GitHub",
            'provider' => Provider::TYPE_GITHUB,
            'token' => 'provider-secret',
            'description' => 'Provider',
        ]);

        return [$owner, $provider];
    }

    private function record(Provider $provider, bool $successful, int $duration, mixed $checkedAt): void
    {
        $provider->connectionChecks()->create([
            'successful' => $successful,
            'source' => ProviderConnectionCheck::SOURCE_AUTOMATIC,
            'provider_type' => Provider::TYPE_GITHUB,
            'http_status' => $successful ? 200 : 401,
            'duration_ms' => $duration,
            'endpoint' => 'https://api.github.com/user',
            'error' => $successful ? null : 'Connection check failed.',
            'checked_at' => $checkedAt,
        ]);
    }
}
