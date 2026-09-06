<?php

namespace Tests\Feature;

use App\Actions\Server\UpdateServerIpAction;
use App\Contracts\ServerProvider;
use App\Data\CloudServerData;
use App\Data\CloudSshKeyData;
use App\Jobs\Server\InitialiseServerJob;
use App\Models\Enums\Server\ServerTypeEnum;
use App\Models\Provider;
use App\Models\Server;
use App\Models\User;
use App\Services\ServerProviderResolver;
use App\Services\SshKeyPair;
use App\Services\SshHostIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class ServerProviderLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_server_creation_uses_the_provider_contract_and_normalized_result(): void
    {
        Queue::fake();
        [$user, $providerModel] = $this->infrastructure();
        $this->mock(SshKeyPair::class, function (MockInterface $mock): void {
            $mock->shouldReceive('publicKey')->once()->andReturn('ssh-ed25519 portable-public-key');
            $mock->shouldReceive('privateKey')->once()->andReturn('portable-private-key');
        });
        $provider = Mockery::mock(ServerProvider::class);
        $provider->shouldReceive('createSshKey')
            ->once()
            ->with('Portable Server', 'ssh-ed25519 portable-public-key')
            ->andReturn(new CloudSshKeyData('portable-fingerprint', true));
        $provider->shouldReceive('createServer')
            ->once()
            ->withArgs(function (array $parameters): bool {
                return $parameters['region'] === 'nyc1'
                    && $parameters['size'] === 's-1vcpu-1gb'
                    && $parameters['image'] === 'ubuntu-22-04-x64'
                    && $parameters['ssh_keys'] === ['portable-fingerprint']
                    && str_contains($parameters['user_data'], 'provisionPing');
            })
            ->andReturn(new CloudServerData(
                identifier: 2468,
                name: 'portable-server',
                region: 'New York 1',
                size: 's-1vcpu-1gb',
                image: 'Ubuntu 22.04',
            ));
        $this->providerResolver($providerModel, $provider);

        $this->actingAs($user)->post(route('servers.store'), [
            'provider_id' => $providerModel->id,
            'type' => ServerTypeEnum::cache->value,
            'name' => 'Portable Server',
            'region' => 'nyc1',
            'image' => 'ubuntu-22-04-x64',
            'size' => 's-1vcpu-1gb',
        ])->assertRedirect();

        $server = Server::query()->sole();
        $this->assertSame('2468', $server->identifier);
        $this->assertSame('portable-server', $server->name);
        $this->assertSame('New York 1', $server->region);
        $this->assertSame('portable-fingerprint', $server->ssh_fingerprint);
        $this->assertTrue($server->ssh_key_owned);
        $this->assertNotNull($server->provisioning_token);
        $this->assertNotNull($server->initialization_token);
        $this->assertNotSame($server->provisioning_token, $server->initialization_token);
        Queue::assertPushed(InitialiseServerJob::class, fn (InitialiseServerJob $job): bool => $job->server->is($server)
            && $job->attemptToken === $server->initialization_token);
    }

    public function test_ip_initialization_uses_normalized_provider_network_data(): void
    {
        [, $providerModel, $server] = $this->infrastructure(withServer: true);
        $provider = Mockery::mock(ServerProvider::class);
        $provider->shouldReceive('server')
            ->once()
            ->with(1357)
            ->andReturn(new CloudServerData(
                identifier: 1357,
                name: 'portable-server',
                region: 'Region',
                size: 'Size',
                image: 'Image',
                publicIp: '203.0.113.10',
                privateIp: '10.0.0.10',
            ));
        $resolver = $this->providerResolver($providerModel, $provider);
        $hostIdentity = Mockery::mock(SshHostIdentity::class);
        $hostIdentity->shouldReceive('scan')
            ->once()
            ->with('203.0.113.10', 22)
            ->andReturn([
                'known_host' => '203.0.113.10 ssh-ed25519 AAAATEST',
                'fingerprint' => 'SHA256:test-host-key',
                'algorithm' => 'ssh-ed25519',
            ]);

        (new InitialiseServerJob($server))->handle(new UpdateServerIpAction($resolver, $hostIdentity));

        $server->refresh();
        $this->assertSame('203.0.113.10', $server->public_ip);
        $this->assertSame('10.0.0.10', $server->private_ip);
        $this->assertSame('203.0.113.10 ssh-ed25519 AAAATEST', $server->ssh_host_key);
        $this->assertSame('SHA256:test-host-key', $server->ssh_host_fingerprint);
        $this->assertSame(Server::STATUS_PROVISIONING, $server->provisioning_status);
        $this->assertNull($server->initialization_token);
    }

    public function test_resolver_rejects_unknown_server_provider_types(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unsupported server provider: unknown-cloud.');

        (new ServerProviderResolver)->resolveCredentials('unknown-cloud', 'secret');
    }

    private function infrastructure(bool $withServer = false): array
    {
        $user = User::factory()->create();
        $provider = $user->providers()->create([
            'name' => 'DigitalOcean',
            'provider' => Provider::TYPE_DIGITALOCEAN,
            'token' => 'provider-secret',
            'description' => 'Cloud provider',
        ]);
        $server = $withServer ? $user->servers()->create([
            'provider_id' => $provider->id,
            'identifier' => 1357,
            'name' => 'Portable Server',
            'provisioning_status' => Server::STATUS_QUEUED,
        ]) : null;

        return [$user, $provider, $server];
    }

    private function providerResolver(Provider $providerModel, ServerProvider $provider): ServerProviderResolver
    {
        $resolver = Mockery::mock(ServerProviderResolver::class);
        $resolver->shouldReceive('resolve')
            ->withArgs(fn (Provider $candidate): bool => $candidate->is($providerModel))
            ->andReturn($provider);
        $this->app->instance(ServerProviderResolver::class, $resolver);

        return $resolver;
    }
}
