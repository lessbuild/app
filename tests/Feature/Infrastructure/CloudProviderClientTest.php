<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use App\Services\Infrastructure\HetznerCloud;
use App\Services\Infrastructure\Vultr;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class CloudProviderClientTest extends TestCase
{
    public function test_hetzner_adapter_uses_supported_endpoints_and_normalizes_a_server(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://api.hetzner.cloud/v1/ssh_keys' => Http::response(['ssh_key' => ['id' => 41]], 201),
            'https://api.hetzner.cloud/v1/servers' => Http::response([
                'server' => [
                    'id' => 91,
                    'name' => 'production',
                    'datacenter' => ['location' => ['name' => 'nbg1']],
                    'server_type' => ['name' => 'cx22'],
                    'image' => ['name' => 'ubuntu-24.04'],
                    'public_net' => ['ipv4' => ['ip' => '203.0.113.10']],
                    'private_net' => [['ip' => '10.0.0.2']],
                ],
            ], 201),
        ]);

        $client = new HetznerCloud('hetzner-secret');
        $key = $client->createSshKey('BuildPusher', 'ssh-ed25519 key');
        $server = $client->createServer([
            'name' => 'production',
            'region' => 'nbg1',
            'size' => 'cx22',
            'image' => 'ubuntu-24.04',
            'ssh_keys' => [$key->fingerprint],
            'user_data' => '#cloud-config',
        ]);

        $this->assertSame('41', $key->fingerprint);
        $this->assertSame('91', $server->identifier);
        $this->assertSame('203.0.113.10', $server->publicIp);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.hetzner.cloud/v1/servers'
            && $request['location'] === 'nbg1'
            && $request['server_type'] === 'cx22'
            && $request['ssh_keys'] === ['41']
            && $request->header('Authorization')[0] === 'Bearer hetzner-secret');
    }

    public function test_vultr_adapter_uses_v2_payload_and_normalizes_uuid_identifiers(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://api.vultr.com/v2/instances' => Http::response([
                'instance' => [
                    'id' => 'a14b6539-5583-41e8-a035-c07a76897f2b',
                    'hostname' => 'production',
                    'region' => 'ewr',
                    'plan' => 'vc2-1c-1gb',
                    'os_id' => 2284,
                    'main_ip' => '203.0.113.11',
                    'internal_ip' => '10.0.0.3',
                ],
            ], 202),
        ]);

        $server = (new Vultr('vultr-secret'))->createServer([
            'name' => 'production',
            'region' => 'ewr',
            'size' => 'vc2-1c-1gb',
            'image' => '2284',
            'ssh_keys' => ['key-uuid'],
            'user_data' => '#cloud-config',
        ]);

        $this->assertSame('a14b6539-5583-41e8-a035-c07a76897f2b', $server->identifier);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.vultr.com/v2/instances'
            && $request['os_id'] === 2284
            && $request['sshkey_id'] === ['key-uuid']
            && $request['user_data'] === base64_encode('#cloud-config')
            && $request->header('Authorization')[0] === 'Bearer vultr-secret');
    }
}
