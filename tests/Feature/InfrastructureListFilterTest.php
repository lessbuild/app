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

class InfrastructureListFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_combine_website_search_status_health_and_attention_filters(): void
    {
        Queue::fake();
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $server = $this->server($owner, 'Owner Edge', Server::STATUS_ACTIVE);
        $matching = $this->website($owner, $server, 'Customer Portal', [
            'url' => 'customer.example.com',
            'provisioning_status' => Website::STATUS_FAILED,
            'health_check_enabled' => true,
            'health_status' => Website::HEALTH_UNHEALTHY,
        ]);
        $this->website($owner, $server, 'Customer Healthy', [
            'url' => 'healthy.example.com',
            'provisioning_status' => Website::STATUS_FAILED,
            'health_check_enabled' => true,
            'health_status' => Website::HEALTH_HEALTHY,
        ]);
        $this->website($owner, $server, 'Unrelated Outage', [
            'url' => 'unrelated.example.com',
            'provisioning_status' => Website::STATUS_FAILED,
            'health_check_enabled' => true,
            'health_status' => Website::HEALTH_UNHEALTHY,
        ]);
        $otherServer = $this->server($other, 'Private Edge', Server::STATUS_ACTIVE);
        $this->website($other, $otherServer, 'Customer Private', [
            'url' => 'private.example.com',
            'provisioning_status' => Website::STATUS_FAILED,
            'health_check_enabled' => true,
            'health_status' => Website::HEALTH_UNHEALTHY,
        ]);

        $this->actingAs($owner)->get(route('websites.index', [
            'search' => 'customer',
            'status' => Website::STATUS_FAILED,
            'health' => Website::HEALTH_UNHEALTHY,
            'attention' => 1,
        ]))
            ->assertSuccessful()
            ->assertSee(route('websites.show', $matching))
            ->assertSee('value="customer"', false)
            ->assertSee('value="failed" selected', false)
            ->assertSee('value="unhealthy" selected', false)
            ->assertSee('name="attention" value="1" checked', false)
            ->assertDontSee('Customer Healthy')
            ->assertDontSee('Unrelated Outage')
            ->assertDontSee('Customer Private');
    }

    public function test_owner_can_filter_servers_by_status_and_any_address_field(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $matching = $this->server($owner, 'Production Edge', Server::STATUS_FAILED, [
            'identifier' => 4242,
            'public_ip' => '203.0.113.10',
            'private_ip' => '10.0.0.10',
        ]);
        $this->server($owner, 'Healthy Edge', Server::STATUS_ACTIVE, [
            'public_ip' => '203.0.113.10',
        ]);
        $this->server($owner, 'Unrelated Failure', Server::STATUS_FAILED, [
            'public_ip' => '203.0.113.20',
        ]);
        $this->server($other, 'Private Production Edge', Server::STATUS_FAILED, [
            'public_ip' => '203.0.113.10',
        ]);

        $this->actingAs($owner)->get(route('servers.index', [
            'search' => '203.0.113.10',
            'status' => Server::STATUS_FAILED,
        ]))
            ->assertSuccessful()
            ->assertSee(route('servers.show', $matching))
            ->assertSee('value="203.0.113.10"', false)
            ->assertSee('value="failed" selected', false)
            ->assertDontSee('Healthy Edge')
            ->assertDontSee('Unrelated Failure')
            ->assertDontSee('Private Production Edge');
    }

    public function test_website_attention_filter_includes_either_current_failure_type(): void
    {
        Queue::fake();
        $owner = User::factory()->create();
        $server = $this->server($owner, 'Production', Server::STATUS_ACTIVE);
        $failed = $this->website($owner, $server, 'Failed Provisioning', [
            'provisioning_status' => Website::STATUS_FAILED,
            'health_check_enabled' => true,
            'health_status' => Website::HEALTH_HEALTHY,
        ]);
        $unhealthy = $this->website($owner, $server, 'Active Outage', [
            'provisioning_status' => Website::STATUS_ACTIVE,
            'health_check_enabled' => true,
            'health_status' => Website::HEALTH_UNHEALTHY,
        ]);
        $disabled = $this->website($owner, $server, 'Disabled Stale State', [
            'provisioning_status' => Website::STATUS_ACTIVE,
            'health_check_enabled' => false,
            'health_status' => Website::HEALTH_UNHEALTHY,
        ]);
        $healthy = $this->website($owner, $server, 'Healthy Website', [
            'provisioning_status' => Website::STATUS_ACTIVE,
            'health_check_enabled' => true,
            'health_status' => Website::HEALTH_HEALTHY,
        ]);

        $this->actingAs($owner)->get(route('websites.index', ['attention' => 1]))
            ->assertSuccessful()
            ->assertSee(route('websites.show', $failed))
            ->assertSee(route('websites.show', $unhealthy))
            ->assertDontSee(route('websites.show', $disabled))
            ->assertDontSee(route('websites.show', $healthy));
    }

    public function test_invalid_filters_are_ignored_and_empty_filtered_results_can_be_reset(): void
    {
        $owner = User::factory()->create();
        $server = $this->server($owner, 'Visible Server', Server::STATUS_ACTIVE);
        $this->website($owner, $server, 'Visible Website');

        $this->actingAs($owner)->get(route('websites.index', [
            'status' => 'destroyed',
            'health' => 'burning',
            'attention' => 'sometimes',
            'search' => '   ',
        ]))
            ->assertSuccessful()
            ->assertSee('Visible Website')
            ->assertDontSee('Clear filters');

        $this->actingAs($owner)->get(route('servers.index', [
            'status' => Server::STATUS_FAILED,
            'search' => 'missing',
        ]))
            ->assertSuccessful()
            ->assertSee('No servers match these filters')
            ->assertSee('Clear filters')
            ->assertSee(route('servers.index'));
    }

    public function test_filter_state_is_preserved_in_pagination_links(): void
    {
        $owner = User::factory()->create();
        foreach (range(1, 16) as $index) {
            $this->server($owner, "Production {$index}", Server::STATUS_FAILED);
        }

        $this->actingAs($owner)->get(route('servers.index', [
            'search' => 'Production',
            'status' => Server::STATUS_FAILED,
        ]))
            ->assertSuccessful()
            ->assertSee('page=2', false)
            ->assertSee('search=Production', false)
            ->assertSee('status=failed', false);
    }

    public function test_website_filter_state_is_preserved_in_pagination_links(): void
    {
        Queue::fake();
        $owner = User::factory()->create();
        $server = $this->server($owner, 'Production', Server::STATUS_ACTIVE);
        foreach (range(1, 16) as $index) {
            $this->website($owner, $server, "Production Website {$index}", [
                'health_check_enabled' => true,
                'health_status' => Website::HEALTH_UNHEALTHY,
            ]);
        }

        $this->actingAs($owner)->get(route('websites.index', [
            'search' => 'Production',
            'health' => Website::HEALTH_UNHEALTHY,
            'attention' => 1,
        ]))
            ->assertSuccessful()
            ->assertSee('page=2', false)
            ->assertSee('search=Production', false)
            ->assertSee('health=unhealthy', false)
            ->assertSee('attention=1', false);
    }

    public function test_dashboard_overflow_links_open_issue_focused_infrastructure_lists(): void
    {
        Queue::fake();
        $owner = User::factory()->create();
        foreach (range(1, 6) as $index) {
            $server = $this->server($owner, "Failed Server {$index}", Server::STATUS_FAILED);
            $this->website($owner, $server, "Failed Website {$index}", [
                'provisioning_status' => Website::STATUS_FAILED,
            ]);
        }

        $this->actingAs($owner)->get(route('dashboard'))
            ->assertSuccessful()
            ->assertSee(route('websites.index', ['attention' => 1]))
            ->assertSee(route('servers.index', ['status' => Server::STATUS_FAILED]));
    }

    /** @param array<string, mixed> $attributes */
    private function server(User $user, string $name, string $status, array $attributes = []): Server
    {
        $provider = $user->providers()->firstOrCreate([
            'name' => 'DigitalOcean',
            'provider' => Provider::TYPE_DIGITALOCEAN,
        ], [
            'description' => 'Cloud provider',
            'token' => 'secret',
        ]);

        return $user->servers()->create([
            'provider_id' => $provider->id,
            'name' => $name,
            'type' => ServerTypeEnum::app,
            'region' => 'nyc3',
            'image' => 'ubuntu-22-04-x64',
            'provisioning_status' => $status,
            ...$attributes,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function website(User $user, Server $server, string $name, array $attributes = []): Website
    {
        return $user->websites()->create([
            'server_id' => $server->id,
            'name' => $name,
            'url' => str($name)->slug().'.example.com',
            'description' => "{$name} description",
            'environment' => 'APP_ENV=production',
            'provisioning_status' => Website::STATUS_ACTIVE,
            ...$attributes,
        ]);
    }
}
