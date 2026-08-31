<?php

namespace Tests\Feature;

use App\Models\Provider;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProviderInventoryFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_combine_search_type_and_usage_filters_with_resource_counts(): void
    {
        $owner = User::factory()->create();
        $matching = $this->provider($owner, 'Production DigitalOcean', Provider::TYPE_DIGITALOCEAN);
        $this->server($owner, $matching, 'Production Edge');
        $this->provider($owner, 'Production Spare', Provider::TYPE_DIGITALOCEAN);
        $source = $this->provider($owner, 'Production GitHub', Provider::TYPE_GITHUB);
        $this->repository($owner, $source, 'Production Repository');

        $other = User::factory()->create();
        $foreign = $this->provider($other, 'Private Production DigitalOcean', Provider::TYPE_DIGITALOCEAN);
        $this->server($other, $foreign, 'Private Edge');

        $filters = [
            'search' => 'Production',
            'type' => Provider::TYPE_DIGITALOCEAN,
            'usage' => 'in_use',
        ];

        $this->actingAs($owner)->get(route('providers.index', $filters))
            ->assertSuccessful()
            ->assertSee(route('providers.show', $matching))
            ->assertSee('1 server')
            ->assertSee('0 repositories')
            ->assertSee('value="Production"', false)
            ->assertSee('value="digitalocean" selected', false)
            ->assertSee('value="in_use" selected', false)
            ->assertDontSee('Production Spare')
            ->assertDontSee('Production GitHub')
            ->assertDontSee('Private Production DigitalOcean');
    }

    public function test_unused_filter_excludes_providers_with_either_resource_type(): void
    {
        $owner = User::factory()->create();
        $unused = $this->provider($owner, 'Unused Provider', Provider::TYPE_GITHUB);
        $cloud = $this->provider($owner, 'Used Cloud', Provider::TYPE_DIGITALOCEAN);
        $this->server($owner, $cloud, 'Cloud Server');
        $source = $this->provider($owner, 'Used Source', Provider::TYPE_GITHUB);
        $this->repository($owner, $source, 'Source Repository');

        $this->actingAs($owner)->get(route('providers.index', ['usage' => 'unused']))
            ->assertSuccessful()
            ->assertSee(route('providers.show', $unused))
            ->assertDontSee('Used Cloud')
            ->assertDontSee('Used Source');
    }

    public function test_invalid_filters_are_ignored_and_empty_results_can_be_reset(): void
    {
        $owner = User::factory()->create();
        $this->provider($owner, 'Visible Provider', Provider::TYPE_GITHUB);

        $this->actingAs($owner)->get(route('providers.index', [
            'search' => '   ',
            'type' => 'unsupported',
            'usage' => 'busy',
        ]))
            ->assertSuccessful()
            ->assertSee('Visible Provider')
            ->assertDontSee('Clear filters');

        $this->actingAs($owner)->get(route('providers.index', ['search' => 'missing']))
            ->assertSuccessful()
            ->assertSee('No providers match these filters')
            ->assertSee('Try changing or clearing the selected filters.')
            ->assertSee('Clear filters');
    }

    public function test_provider_filter_state_is_preserved_in_pagination_links(): void
    {
        $owner = User::factory()->create();
        foreach (range(1, 16) as $index) {
            $this->provider($owner, "Fleet Provider {$index}", Provider::TYPE_GITHUB);
        }

        $this->actingAs($owner)->get(route('providers.index', [
            'search' => 'Fleet',
            'type' => Provider::TYPE_GITHUB,
            'usage' => 'unused',
        ]))
            ->assertSuccessful()
            ->assertSee('page=2', false)
            ->assertSee('search=Fleet', false)
            ->assertSee('type=github', false)
            ->assertSee('usage=unused', false);
    }

    public function test_provider_detail_paginates_all_attached_servers_and_repositories(): void
    {
        $owner = User::factory()->create();
        $cloud = $this->provider($owner, 'DigitalOcean', Provider::TYPE_DIGITALOCEAN);
        foreach (range(1, 16) as $index) {
            $this->server($owner, $cloud, "Server {$index}");
        }

        $this->actingAs($owner)->get(route('providers.show', $cloud))
            ->assertSuccessful()
            ->assertSee('servers_page=2', false);

        $source = $this->provider($owner, 'GitHub', Provider::TYPE_GITHUB);
        foreach (range(1, 16) as $index) {
            $this->repository($owner, $source, "Repository {$index}");
        }

        $this->actingAs($owner)->get(route('providers.show', $source))
            ->assertSuccessful()
            ->assertSee('repositories_page=2', false);
    }

    private function provider(User $user, string $name, string $type): Provider
    {
        return $user->providers()->create([
            'name' => $name,
            'provider' => $type,
            'token' => 'secret',
            'description' => "{$name} description",
        ]);
    }

    private function server(User $user, Provider $provider, string $name): Server
    {
        return $user->servers()->create([
            'provider_id' => $provider->id,
            'name' => $name,
            'provisioning_status' => Server::STATUS_ACTIVE,
        ]);
    }

    private function repository(User $user, Provider $provider, string $name): void
    {
        $server = $user->servers()->create([
            'name' => "{$name} Server",
            'provisioning_status' => Server::STATUS_ACTIVE,
        ]);
        $website = $user->websites()->create([
            'server_id' => $server->id,
            'name' => "{$name} Website",
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
