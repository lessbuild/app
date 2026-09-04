<?php

namespace Tests\Feature;

use App\Models\Enums\Server\ServerTypeEnum;
use App\Models\Provider;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServerInventoryInsightsTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_sees_filtered_capacity_counts_without_foreign_or_secret_data(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $provider = $this->provider($owner, 'Owner provider');
        $foreignProvider = $this->provider($other, 'Foreign provider');
        $latestAt = now()->subMinute();
        $queued = $this->server($owner, $provider, 'Queued server', Server::STATUS_QUEUED, now()->subDays(5));
        $waiting = $this->server($owner, $provider, 'Waiting server', Server::STATUS_WAITING_FOR_IP, now()->subDays(4));
        $provisioning = $this->server($owner, $provider, 'Provisioning server', Server::STATUS_PROVISIONING, now()->subDays(3));
        $active = $this->server($owner, $provider, 'Ready server', Server::STATUS_ACTIVE, now()->subDays(2));
        $failed = $this->server($owner, $provider, 'Failed server', Server::STATUS_FAILED, $latestAt, [
            'ssh_private_key' => 'owner-private-key-secret',
        ]);
        $this->website($owner, $queued, 'Queued website');
        $this->website($owner, $active, 'Ready website one');
        $this->website($owner, $active, 'Ready website two');
        $foreign = $this->server($other, $foreignProvider, 'Foreign private server', Server::STATUS_ACTIVE, now());
        $this->website($other, $foreign, 'Foreign private website');

        $this->actingAs($owner)->get(route('servers.index'))
            ->assertSuccessful()
            ->assertViewHas('metrics', fn (array $metrics): bool => $metrics['total'] === 5
                && $metrics['ready'] === 1
                && $metrics['provisioning'] === 3
                && $metrics['failed'] === 1
                && $metrics['websites'] === 3
                && $metrics['latest_at']->timestamp === $latestAt->timestamp)
            ->assertSee('Matching servers')
            ->assertSee('Ready servers')
            ->assertSee('Provisioning')
            ->assertSee('Failed servers')
            ->assertSee('Hosted websites')
            ->assertSee('Latest matching server')
            ->assertDontSee('Foreign private server')
            ->assertDontSee('Foreign private website')
            ->assertDontSee('owner-private-key-secret');

        $this->assertTrue($waiting->exists && $provisioning->exists && $failed->exists);
    }

    public function test_metrics_apply_search_and_status_filters_to_servers_and_attached_websites(): void
    {
        $owner = User::factory()->create();
        $provider = $this->provider($owner, 'Owner provider');
        $matchingAt = now()->subDay();
        $matching = $this->server($owner, $provider, 'Production failed', Server::STATUS_FAILED, $matchingAt, [
            'public_ip' => '203.0.113.10',
        ]);
        $this->website($owner, $matching, 'First matching website');
        $this->website($owner, $matching, 'Second matching website');
        $this->server($owner, $provider, 'Production ready', Server::STATUS_ACTIVE, now(), [
            'public_ip' => '203.0.113.10',
        ]);
        $this->server($owner, $provider, 'Other failure', Server::STATUS_FAILED, now(), [
            'public_ip' => '203.0.113.20',
        ]);

        $this->actingAs($owner)->get(route('servers.index', [
            'search' => '203.0.113.10',
            'status' => Server::STATUS_FAILED,
        ]))
            ->assertSuccessful()
            ->assertViewHas('servers', fn ($servers): bool => $servers->count() === 1
                && $servers->sole()->id === $matching->id)
            ->assertViewHas('metrics', fn (array $metrics): bool => $metrics['total'] === 1
                && $metrics['ready'] === 0
                && $metrics['provisioning'] === 0
                && $metrics['failed'] === 1
                && $metrics['websites'] === 2
                && $metrics['latest_at']->timestamp === $matchingAt->timestamp);
    }

    public function test_empty_filtered_inventory_has_explicit_zero_and_unknown_metrics(): void
    {
        $owner = User::factory()->create();
        $provider = $this->provider($owner, 'Owner provider');
        $this->server($owner, $provider, 'Ready server', Server::STATUS_ACTIVE, now());

        $this->actingAs($owner)->get(route('servers.index', [
            'search' => 'missing-server',
            'status' => Server::STATUS_FAILED,
        ]))
            ->assertSuccessful()
            ->assertViewHas('metrics', [
                'total' => 0,
                'ready' => 0,
                'provisioning' => 0,
                'failed' => 0,
                'websites' => 0,
                'latest_at' => null,
            ])
            ->assertSee('Not available')
            ->assertSee('No matching server recorded.')
            ->assertSee('No servers match these filters');
    }

    private function provider(User $user, string $name): Provider
    {
        return $user->providers()->create([
            'name' => $name,
            'provider' => Provider::TYPE_DIGITALOCEAN,
            'token' => 'provider-token',
            'description' => 'Cloud provider',
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function server(
        User $user,
        Provider $provider,
        string $name,
        string $status,
        mixed $createdAt,
        array $attributes = [],
    ): Server {
        return $user->servers()->create([
            'provider_id' => $provider->id,
            'name' => $name,
            'type' => ServerTypeEnum::app,
            'region' => 'nyc3',
            'size' => 's-1vcpu-1gb',
            'image' => 'ubuntu-22-04-x64',
            'provisioning_status' => $status,
            'created_at' => $createdAt,
            ...$attributes,
        ]);
    }

    private function website(User $user, Server $server, string $name): Website
    {
        return $user->websites()->create([
            'server_id' => $server->id,
            'name' => $name,
            'url' => str($name)->slug().'.example.com',
            'description' => 'Website',
            'environment' => 'APP_ENV=production',
            'provisioning_status' => Website::STATUS_ACTIVE,
        ]);
    }
}
