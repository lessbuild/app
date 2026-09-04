<?php

namespace Tests\Feature;

use App\Models\Enums\Server\ServerTypeEnum;
use App\Models\Provider;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WebsiteInventoryInsightsTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_sees_provisioning_health_and_attention_counts_without_foreign_or_secret_data(): void
    {
        Queue::fake();
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $server = $this->server($owner, 'Owner server');
        $foreignServer = $this->server($other, 'Foreign server');
        $this->website($owner, $server, 'Queued site', Website::STATUS_QUEUED);
        $this->website($owner, $server, 'Provisioning site', Website::STATUS_PROVISIONING);
        $this->website($owner, $server, 'Healthy site', Website::STATUS_ACTIVE, true, Website::HEALTH_HEALTHY);
        $this->website($owner, $server, 'Unhealthy site', Website::STATUS_ACTIVE, true, Website::HEALTH_UNHEALTHY);
        $this->website($owner, $server, 'Failed site', Website::STATUS_FAILED);
        $this->website($owner, $server, 'Disabled stale site', Website::STATUS_ACTIVE, false, Website::HEALTH_UNHEALTHY, [
            'environment' => 'PRIVATE_WEBSITE_SECRET=never-render',
        ]);
        $this->website($other, $foreignServer, 'Foreign private site', Website::STATUS_FAILED, true, Website::HEALTH_UNHEALTHY);

        $this->actingAs($owner)->get(route('websites.index'))
            ->assertSuccessful()
            ->assertViewHas('metrics', [
                'total' => 6,
                'active' => 3,
                'provisioning' => 2,
                'failed' => 1,
                'unhealthy' => 1,
                'attention' => 2,
            ])
            ->assertSee('Matching websites')
            ->assertSee('Active websites')
            ->assertSee('Provisioning')
            ->assertSee('Failed websites')
            ->assertSee('Unhealthy websites')
            ->assertSee('Needs attention')
            ->assertDontSee('Foreign private site')
            ->assertDontSee('PRIVATE_WEBSITE_SECRET');
    }

    public function test_metrics_apply_search_status_health_and_attention_filters_together(): void
    {
        Queue::fake();
        $owner = User::factory()->create();
        $server = $this->server($owner, 'Owner server');
        $matching = $this->website(
            $owner,
            $server,
            'Customer outage',
            Website::STATUS_FAILED,
            true,
            Website::HEALTH_UNHEALTHY,
        );
        $this->website($owner, $server, 'Customer recovered', Website::STATUS_FAILED, true, Website::HEALTH_HEALTHY);
        $this->website($owner, $server, 'Internal outage', Website::STATUS_FAILED, true, Website::HEALTH_UNHEALTHY);

        $this->actingAs($owner)->get(route('websites.index', [
            'search' => 'Customer',
            'status' => Website::STATUS_FAILED,
            'health' => Website::HEALTH_UNHEALTHY,
            'attention' => 1,
        ]))
            ->assertSuccessful()
            ->assertViewHas('websites', fn ($websites): bool => $websites->count() === 1
                && $websites->sole()->id === $matching->id)
            ->assertViewHas('metrics', [
                'total' => 1,
                'active' => 0,
                'provisioning' => 0,
                'failed' => 1,
                'unhealthy' => 1,
                'attention' => 1,
            ]);
    }

    public function test_empty_filtered_inventory_has_explicit_zero_metrics(): void
    {
        Queue::fake();
        $owner = User::factory()->create();
        $server = $this->server($owner, 'Owner server');
        $this->website($owner, $server, 'Healthy site', Website::STATUS_ACTIVE, true, Website::HEALTH_HEALTHY);

        $this->actingAs($owner)->get(route('websites.index', [
            'search' => 'missing-site',
            'attention' => 1,
        ]))
            ->assertSuccessful()
            ->assertViewHas('metrics', [
                'total' => 0,
                'active' => 0,
                'provisioning' => 0,
                'failed' => 0,
                'unhealthy' => 0,
                'attention' => 0,
            ])
            ->assertSee('No websites match these filters');
    }

    private function server(User $user, string $name): Server
    {
        $provider = $user->providers()->create([
            'name' => "{$name} provider",
            'provider' => Provider::TYPE_DIGITALOCEAN,
            'token' => 'provider-token',
            'description' => 'Cloud provider',
        ]);

        return $user->servers()->create([
            'provider_id' => $provider->id,
            'name' => $name,
            'type' => ServerTypeEnum::app,
            'region' => 'nyc3',
            'size' => 's-1vcpu-1gb',
            'image' => 'ubuntu-22-04-x64',
            'provisioning_status' => Server::STATUS_ACTIVE,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function website(
        User $user,
        Server $server,
        string $name,
        string $status,
        bool $healthEnabled = false,
        string $healthStatus = Website::HEALTH_UNKNOWN,
        array $attributes = [],
    ): Website {
        return $user->websites()->create([
            'server_id' => $server->id,
            'name' => $name,
            'url' => str($name)->slug().'.example.com',
            'description' => 'Website',
            'environment' => 'APP_ENV=production',
            'provisioning_status' => $status,
            'health_check_enabled' => $healthEnabled,
            'health_status' => $healthStatus,
            ...$attributes,
        ]);
    }
}
