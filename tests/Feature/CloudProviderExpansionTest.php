<?php

namespace Tests\Feature;

use App\Models\Provider;
use App\Models\User;
use App\Services\HetznerCloud;
use App\Services\Vultr;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CloudProviderExpansionTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_server_catalog_returns_normalized_current_vultr_options(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://api.vultr.com/v2/regions' => Http::response(['regions' => [
                ['id' => 'ewr', 'city' => 'New Jersey', 'country' => 'US'],
            ]]),
            'https://api.vultr.com/v2/plans*' => Http::response(['plans' => [
                ['id' => 'vc2-1c-1gb', 'ram' => 1024, 'vcpu_count' => 1, 'monthly_cost' => 5],
            ]]),
            'https://api.vultr.com/v2/os*' => Http::response(['os' => [
                ['id' => 2284, 'name' => 'Ubuntu 24.04 LTS x64'],
                ['id' => 2136, 'name' => 'Debian 12 x64'],
            ]]),
        ]);
        $owner = User::factory()->create();
        $provider = $owner->providers()->create([
            'organization_id' => $owner->current_organization_id,
            'name' => 'Vultr',
            'provider' => Provider::TYPE_VULTR,
            'token' => 'vultr-secret',
            'description' => 'Cloud infrastructure',
        ]);

        $this->actingAs($owner)->getJson(route('providers.server-catalog', $provider))
            ->assertOk()
            ->assertExactJson([
                'regions' => [['id' => 'ewr', 'label' => 'New Jersey, US']],
                'sizes' => [['id' => 'vc2-1c-1gb', 'label' => 'vc2-1c-1gb · 1 GB RAM · 1 vCPU · $5/month']],
                'images' => [['id' => '2284', 'label' => 'Ubuntu 24.04 LTS x64']],
            ]);
    }

    public function test_catalog_is_not_available_to_another_workspace(): void
    {
        Http::preventStrayRequests();
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $provider = $owner->providers()->create([
            'organization_id' => $owner->current_organization_id,
            'name' => 'Hetzner',
            'provider' => Provider::TYPE_HETZNER,
            'token' => 'private-secret',
            'description' => 'Cloud infrastructure',
        ]);

        $this->actingAs($intruder)->getJson(route('providers.server-catalog', $provider))->assertForbidden();
        Http::assertNothingSent();
    }
}
