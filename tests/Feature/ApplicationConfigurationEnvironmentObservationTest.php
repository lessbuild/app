<?php

namespace Tests\Feature;

use App\Contracts\ServerProvider;
use App\Data\CloudServerData;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Provider;
use App\Models\Server;
use App\Models\User;
use App\Services\ApplicationConfigurationEnvironmentObservationQuery;
use App\Services\ServerProviderResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ApplicationConfigurationEnvironmentObservationTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_can_explicitly_observe_normalized_provider_fields_without_persisting_or_exposing_secrets(): void
    {
        [$owner, $project, $environment, $server, $provider] = $this->projectEnvironment(withServer: true);
        $server->update([
            'identifier' => 'server-1', 'name' => 'Recorded server', 'region' => 'nyc3',
            'size' => 's-1vcpu-1gb', 'image' => 'Ubuntu 22.04', 'public_ip' => '203.0.113.10',
            'private_ip' => '10.0.0.10',
        ]);
        $environment->variables()->create([
            'key' => 'PRIVATE_TOKEN', 'value' => 'do-not-render', 'is_secret' => true,
            'scope' => 'runtime', 'current_version' => 1, 'updated_by' => $owner->id,
        ]);

        $client = Mockery::mock(ServerProvider::class);
        $client->shouldReceive('name')->once()->andReturn('Test Cloud');
        $client->shouldReceive('server')->once()->with('server-1')->andReturn(new CloudServerData(
            identifier: 'server-1',
            name: 'Remote server',
            region: 'nyc3',
            size: 's-1vcpu-1gb',
            image: 'Ubuntu 22.04',
            publicIp: '203.0.113.10',
            privateIp: '10.0.0.10',
            providerStatus: 'active',
            readiness: CloudServerData::READINESS_READY,
        ));
        $this->resolver($provider, $client);

        $response = $this->actingAs($owner)->get(route('projects.configuration.observe', [
            'project' => $project,
            'environment_id' => $environment->id,
        ]));

        $response
            ->assertOk()
            ->assertSee('Observed provider state')
            ->assertSee('Test Cloud')
            ->assertSee('Remote server')
            ->assertSee('Provider readiness: Ready')
            ->assertSee('Provider lifecycle: active')
            ->assertSee('Different')
            ->assertSee('Matches')
            ->assertDontSee('do-not-render');
        $this->assertSame('Recorded server', $server->fresh()->name);
        $this->assertDatabaseCount('configuration_applications', 0);
    }

    public function test_observation_distinguishes_a_provider_server_that_is_not_ready(): void
    {
        [$owner, $project, $environment, $server, $provider] = $this->projectEnvironment(withServer: true);
        $server->update(['identifier' => 'server-1']);
        $client = Mockery::mock(ServerProvider::class);
        $client->shouldReceive('name')->once()->andReturn('Test Cloud');
        $client->shouldReceive('server')->once()->with('server-1')->andReturn(new CloudServerData(
            identifier: 'server-1',
            name: 'Stopped server',
            region: 'nyc3',
            size: 's-1vcpu-1gb',
            image: 'Ubuntu 22.04',
            publicIp: '203.0.113.10',
            providerStatus: 'off',
            readiness: CloudServerData::READINESS_NOT_READY,
        ));
        $this->resolver($provider, $client);

        $this->actingAs($owner)
            ->get(route('projects.configuration.observe', [
                'project' => $project,
                'environment_id' => $environment->id,
            ]))
            ->assertOk()
            ->assertSee('Provider readiness: Not Ready')
            ->assertSee('Provider lifecycle: off');
    }

    public function test_missing_server_or_identifier_is_reported_unavailable_without_resolving_a_provider(): void
    {
        [$owner, $project, $environment, $server, $provider] = $this->projectEnvironment(withServer: true);
        $server->update(['provider_id' => null, 'identifier' => null]);
        $this->resolver($provider, Mockery::mock(ServerProvider::class), never: true);

        $this->actingAs($owner)
            ->get(route('projects.configuration.observe', [
                'project' => $project,
                'environment_id' => $environment->id,
            ]))
            ->assertOk()
            ->assertSee('Observed provider state')
            ->assertSee('no available workspace provider connection');
    }

    public function test_provider_failures_are_unknown_and_do_not_render_exception_details(): void
    {
        [$owner, $project, $environment, $server, $provider] = $this->projectEnvironment(withServer: true);
        $server->update(['identifier' => 'server-1']);
        $client = Mockery::mock(ServerProvider::class);
        $client->shouldReceive('server')->once()->with('server-1')->andThrow(new RuntimeException('credential-never-render'));
        $this->resolver($provider, $client);

        $this->actingAs($owner)
            ->get(route('projects.configuration.observe', [
                'project' => $project,
                'environment_id' => $environment->id,
            ]))
            ->assertOk()
            ->assertSee('provider could not confirm the current server state')
            ->assertDontSee('credential-never-render')
            ->assertDontSee('provider-secret');
    }

    public function test_observation_authorizes_before_validating_and_scopes_environment_to_project(): void
    {
        [$owner, $project, $environment] = $this->projectEnvironment();
        $intruder = User::factory()->create();
        $this->resolver(null, Mockery::mock(ServerProvider::class), never: true);

        $this->actingAs($intruder)
            ->get(route('projects.configuration.observe', [
                'project' => $project,
                'environment_id' => 'malformed',
            ]))
            ->assertForbidden();

        $foreign = User::factory()->create();
        $foreignProject = $foreign->currentOrganization->projects()->create([
            'name' => 'Foreign', 'slug' => 'foreign', 'created_by' => $foreign->id,
        ]);
        $foreignEnvironment = $foreignProject->environments()->create([
            'name' => 'Foreign environment', 'slug' => 'foreign', 'type' => 'staging',
        ]);

        $this->actingAs($owner)
            ->get(route('projects.configuration.observe', [
                'project' => $project,
                'environment_id' => $foreignEnvironment->id,
            ]))
            ->assertSessionHasErrors('environment_id');
        $this->assertNotNull($environment->fresh());
    }

    public function test_query_returns_null_for_an_environment_outside_the_project(): void
    {
        [$owner, $project, $environment] = $this->projectEnvironment();
        $foreign = User::factory()->create();
        $foreignProject = $foreign->currentOrganization->projects()->create([
            'name' => 'Foreign', 'slug' => 'foreign', 'created_by' => $foreign->id,
        ]);

        $result = app(ApplicationConfigurationEnvironmentObservationQuery::class)->for($project, $foreignProject->environments()->create([
            'name' => 'Foreign environment', 'slug' => 'foreign', 'type' => 'staging',
        ])->id);

        $this->assertNull($result);
        $this->assertNotNull($environment->fresh());
    }

    /**
     * @return array{0: User, 1: Project, 2: Environment, 3: Server|null, 4: Provider|null}
     */
    private function projectEnvironment(bool $withServer = false): array
    {
        $owner = User::factory()->create();
        $project = $owner->currentOrganization->projects()->create([
            'name' => 'Storefront', 'slug' => 'storefront', 'created_by' => $owner->id,
        ]);
        $provider = $withServer ? $owner->providers()->create([
            'name' => 'DigitalOcean', 'provider' => Provider::TYPE_DIGITALOCEAN,
            'token' => 'provider-secret', 'description' => 'Cloud provider',
        ]) : null;
        $server = $withServer ? $owner->servers()->create([
            'provider_id' => $provider->id, 'name' => 'Recorded server',
            'provisioning_status' => Server::STATUS_ACTIVE,
        ]) : null;
        $environment = $project->environments()->create([
            'server_id' => $server?->id, 'name' => 'Staging', 'slug' => 'staging',
            'type' => 'staging', 'branch' => 'develop', 'runtime_type' => 'php', 'status' => 'ready',
        ]);

        return [$owner, $project, $environment, $server, $provider];
    }

    private function resolver(?Provider $provider, ServerProvider $client, bool $never = false): void
    {
        $resolver = Mockery::mock(ServerProviderResolver::class);
        if ($never) {
            $resolver->shouldReceive('resolve')->never();
        } elseif ($provider) {
            $resolver->shouldReceive('resolve')->once()->withArgs(fn (Provider $candidate): bool => $candidate->is($provider))->andReturn($client);
        }
        $this->app->instance(ServerProviderResolver::class, $resolver);
    }
}
