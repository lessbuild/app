<?php

namespace Tests\Feature;

use App\Contracts\ServerProvider;
use App\Data\CloudServerData;
use App\Services\DigitalOcean;
use App\Services\HetznerCloud;
use App\Services\Vultr;
use Illuminate\Http\Client\ResponseSequence;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ServerProviderContractTest extends TestCase
{
    public function test_all_server_providers_normalize_running_and_stopped_states(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://api.digitalocean.com/v2/droplets/101' => Http::response([
                'droplet' => [
                    'id' => 101, 'name' => 'do-running', 'status' => 'active',
                    'region' => ['name' => 'nyc3'], 'size' => ['slug' => 's-1vcpu-1gb'],
                    'image' => ['name' => 'Ubuntu 24.04'],
                    'networks' => ['v4' => [['type' => 'public', 'ip_address' => '203.0.113.10']]],
                ],
            ]),
            'https://api.digitalocean.com/v2/droplets/102' => Http::response([
                'droplet' => [
                    'id' => 102, 'name' => 'do-stopped', 'status' => 'off',
                    'region' => ['name' => 'nyc3'], 'size' => ['slug' => 's-1vcpu-1gb'],
                    'image' => ['name' => 'Ubuntu 24.04'], 'networks' => ['v4' => []],
                ],
            ]),
            'https://api.hetzner.cloud/v1/servers/201' => Http::response(['server' => [
                'id' => 201, 'name' => 'hetzner-running', 'status' => 'running',
                'datacenter' => ['location' => ['name' => 'nbg1']],
                'server_type' => ['name' => 'cx22'], 'image' => ['name' => 'Ubuntu 24.04'],
                'public_net' => ['ipv4' => ['ip' => '203.0.113.20']], 'private_net' => [],
            ]]),
            'https://api.hetzner.cloud/v1/servers/202' => Http::response(['server' => [
                'id' => 202, 'name' => 'hetzner-stopped', 'status' => 'off',
                'datacenter' => ['location' => ['name' => 'nbg1']],
                'server_type' => ['name' => 'cx22'], 'image' => ['name' => 'Ubuntu 24.04'],
                'public_net' => ['ipv4' => ['ip' => '203.0.113.21']], 'private_net' => [],
            ]]),
            'https://api.vultr.com/v2/instances/301' => Http::response(['instance' => [
                'id' => '301', 'hostname' => 'vultr-running', 'power_status' => 'running',
                'region' => 'ewr', 'plan' => 'vc2-1c-1gb', 'os_id' => 2284,
                'main_ip' => '203.0.113.30', 'internal_ip' => '10.0.0.30',
            ]]),
            'https://api.vultr.com/v2/instances/302' => Http::response(['instance' => [
                'id' => '302', 'hostname' => 'vultr-stopped', 'power_status' => 'stopped',
                'region' => 'ewr', 'plan' => 'vc2-1c-1gb', 'os_id' => 2284,
                'main_ip' => '203.0.113.31', 'internal_ip' => '10.0.0.31',
            ]]),
        ]);

        $providers = [
            [new DigitalOcean('digitalocean-secret'), '101', '102', 'active', 'off'],
            [new HetznerCloud('hetzner-secret'), '201', '202', 'running', 'off'],
            [new Vultr('vultr-secret'), '301', '302', 'running', 'stopped'],
        ];

        foreach ($providers as [$provider, $readyId, $stoppedId, $readyState, $stoppedState]) {
            $ready = $provider->server($readyId);
            $stopped = $provider->server($stoppedId);

            $this->assertSame(CloudServerData::READINESS_READY, $ready->readiness);
            $this->assertSame($readyState, $ready->providerStatus);
            $this->assertSame(CloudServerData::READINESS_NOT_READY, $stopped->readiness);
            $this->assertSame($stoppedState, $stopped->providerStatus);
        }
    }

    public function test_all_server_providers_treat_absent_resources_as_success_and_other_failures_as_failure(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://api.digitalocean.com/v2/droplets/*' => $this->deletionSequence(),
            'https://api.digitalocean.com/v2/account/keys/*' => $this->deletionSequence(),
            'https://api.hetzner.cloud/v1/servers/*' => $this->deletionSequence(),
            'https://api.hetzner.cloud/v1/ssh_keys/*' => $this->deletionSequence(),
            'https://api.vultr.com/v2/instances/*' => $this->deletionSequence(),
            'https://api.vultr.com/v2/ssh-keys/*' => $this->deletionSequence(),
        ]);

        $providers = [
            new DigitalOcean('digitalocean-secret'),
            new HetznerCloud('hetzner-secret'),
            new Vultr('vultr-secret'),
        ];

        foreach ($providers as $provider) {
            $this->assertInstanceOf(ServerProvider::class, $provider);
            $this->assertTrue($provider->deleteServer('server-1'));
            $this->assertFalse($provider->deleteServer('server-1'));
            $this->assertTrue($provider->deleteSshKey('key-1'));
            $this->assertFalse($provider->deleteSshKey('key-1'));
        }

        Http::assertSentCount(12);
    }

    private function deletionSequence(): ResponseSequence
    {
        return Http::sequence()
            ->push([], 404)
            ->push([], 503);
    }
}
