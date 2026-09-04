<?php

namespace Tests\Feature;

use App\Models\Provider;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProviderInventoryInsightsTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_sees_usage_and_connection_counts_without_foreign_or_secret_data(): void
    {
        $owner = User::factory()->create();
        $healthyUsed = $this->provider($owner, 'Healthy cloud', Provider::TYPE_DIGITALOCEAN, Provider::CONNECTION_HEALTHY);
        $this->server($owner, $healthyUsed, 'Production server');
        $failedUsed = $this->provider($owner, 'Failed source', Provider::TYPE_GITHUB, Provider::CONNECTION_FAILED);
        $this->repository($owner, $failedUsed, 'Application repository');
        $this->provider($owner, 'Unchecked spare', Provider::TYPE_GITHUB);
        $this->provider($owner, 'Healthy spare', Provider::TYPE_GITLAB, Provider::CONNECTION_HEALTHY);

        $other = User::factory()->create();
        $foreign = $this->provider($other, 'Foreign failed provider', Provider::TYPE_GITHUB, Provider::CONNECTION_FAILED);
        $this->server($other, $foreign, 'Foreign server');

        $this->actingAs($owner)->get(route('providers.index'))
            ->assertSuccessful()
            ->assertViewHas('metrics', [
                'total' => 4,
                'in_use' => 2,
                'unused' => 2,
                'healthy' => 2,
                'failed' => 1,
                'unchecked' => 1,
            ])
            ->assertSee('Matching providers')
            ->assertSee('In use')
            ->assertSee('Unused')
            ->assertSee('Healthy connections')
            ->assertSee('Failed connections')
            ->assertSee('Unchecked connections')
            ->assertDontSee('Foreign failed provider')
            ->assertDontSee('provider-token-secret');
    }

    public function test_metrics_apply_search_type_usage_and_connection_filters(): void
    {
        $owner = User::factory()->create();
        $matching = $this->provider($owner, 'Production failed source', Provider::TYPE_GITHUB, Provider::CONNECTION_FAILED);
        $this->repository($owner, $matching, 'Production application');
        $healthy = $this->provider($owner, 'Production healthy source', Provider::TYPE_GITHUB, Provider::CONNECTION_HEALTHY);
        $this->repository($owner, $healthy, 'Production website');
        $this->provider($owner, 'Production unused source', Provider::TYPE_GITHUB, Provider::CONNECTION_FAILED);
        $cloud = $this->provider($owner, 'Production failed cloud', Provider::TYPE_DIGITALOCEAN, Provider::CONNECTION_FAILED);
        $this->server($owner, $cloud, 'Production cloud');

        $this->actingAs($owner)->get(route('providers.index', [
            'search' => 'Production',
            'type' => Provider::TYPE_GITHUB,
            'usage' => 'in_use',
            'connection' => Provider::CONNECTION_FAILED,
        ]))
            ->assertSuccessful()
            ->assertViewHas('providers', fn ($providers): bool => $providers->count() === 1
                && $providers->sole()->id === $matching->id)
            ->assertViewHas('metrics', [
                'total' => 1,
                'in_use' => 1,
                'unused' => 0,
                'healthy' => 0,
                'failed' => 1,
                'unchecked' => 0,
            ]);
    }

    public function test_unused_and_empty_filters_have_explicit_metrics(): void
    {
        $owner = User::factory()->create();
        $unused = $this->provider($owner, 'Ready source', Provider::TYPE_GITHUB);

        $this->actingAs($owner)->get(route('providers.index', ['usage' => 'unused']))
            ->assertSuccessful()
            ->assertViewHas('providers', fn ($providers): bool => $providers->sole()->id === $unused->id)
            ->assertViewHas('metrics', [
                'total' => 1,
                'in_use' => 0,
                'unused' => 1,
                'healthy' => 0,
                'failed' => 0,
                'unchecked' => 1,
            ]);

        $this->actingAs($owner)->get(route('providers.index', ['search' => 'missing-provider']))
            ->assertSuccessful()
            ->assertViewHas('metrics', [
                'total' => 0,
                'in_use' => 0,
                'unused' => 0,
                'healthy' => 0,
                'failed' => 0,
                'unchecked' => 0,
            ])
            ->assertSee('No providers match these filters');
    }

    private function provider(User $user, string $name, string $type, ?string $connection = null): Provider
    {
        return $user->providers()->create([
            'name' => $name,
            'provider' => $type,
            'token' => 'provider-token-secret',
            'description' => "{$name} description",
            'connection_status' => $connection,
            'connection_checked_at' => $connection === null ? null : now(),
        ]);
    }

    private function server(User $user, ?Provider $provider, string $name): Server
    {
        return $user->servers()->create([
            'provider_id' => $provider?->id,
            'name' => $name,
            'provisioning_status' => Server::STATUS_ACTIVE,
        ]);
    }

    private function repository(User $user, Provider $provider, string $name): void
    {
        $server = $this->server($user, null, "{$name} server");
        $website = $user->websites()->create([
            'server_id' => $server->id,
            'name' => "{$name} website",
            'url' => str($name)->slug().'.example.com',
            'description' => 'Website',
            'environment' => 'APP_ENV=production',
            'provisioning_status' => Website::STATUS_ACTIVE,
        ]);

        $user->repositories()->create([
            'provider_id' => $provider->id,
            'website_id' => $website->id,
            'name' => $name,
            'url' => 'github.com/example/application.git',
            'branch' => 'main',
            'description' => 'Repository',
        ]);
    }
}
