<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use App\Data\Infrastructure\CloudServerData;
use App\Enums\ProviderType;
use App\Models\Provider;
use App\Services\Infrastructure\Linode;
use App\Services\Infrastructure\ServerCatalog;
use App\Services\Infrastructure\ServerPricing;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class LinodeClientTest extends TestCase
{
    /**
     * Check that the Linode adapter registers keys once, creates instances with the key material, a throwaway root
     * password and base64 user data, and reads readiness from the status and public address.
     *
     * @return void
     */
    public function test_linode_creates_and_reads_instances(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://api.linode.com/v4/profile/sshkeys?page_size=500' => Http::response(['data' => [['id' => 7, 'ssh_key' => 'ssh-ed25519 AAAAexisting']]]),
            'https://api.linode.com/v4/profile/sshkeys' => Http::response(['id' => 8], 200),
            'https://api.linode.com/v4/profile/sshkeys/8' => Http::response(['id' => 8, 'ssh_key' => 'ssh-ed25519 AAAAnew']),
            'https://api.linode.com/v4/linode/instances' => Http::response(['id' => 501, 'label' => 'shop-web-1', 'status' => 'provisioning', 'region' => 'eu-west', 'type' => 'g6-standard-1', 'image' => 'linode/ubuntu24.04', 'ipv4' => ['45.33.10.20', '192.168.140.5']]),
            'https://api.linode.com/v4/linode/instances/501' => Http::response(['id' => 501, 'label' => 'shop-web-1', 'status' => 'running', 'region' => 'eu-west', 'type' => 'g6-standard-1', 'image' => 'linode/ubuntu24.04', 'ipv4' => ['45.33.10.20', '192.168.140.5']]),
        ]);
        $linode = new Linode('linode-secret');

        $this->assertSame(['7', false], [($existing = $linode->createSshKey('key', 'ssh-ed25519 AAAAexisting'))->fingerprint, $existing->created]);
        $this->assertSame(['8', true], [($new = $linode->createSshKey('key', 'ssh-ed25519 AAAAnew'))->fingerprint, $new->created]);

        $server = $linode->createServer(['name' => 'shop web 1', 'region' => 'eu-west', 'size' => 'g6-standard-1', 'image' => 'linode/ubuntu24.04', 'ssh_keys' => ['8'], 'user_data' => "#!/bin/sh\necho hi"]);
        $this->assertSame(['501', '45.33.10.20', '192.168.140.5', CloudServerData::READINESS_NOT_READY], [$server->identifier, $server->publicIp, $server->privateIp, $server->readiness]);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.linode.com/v4/linode/instances' && $request->method() === 'POST'
            && $request['label'] === 'shop-web-1' && $request['authorized_keys'] === ['ssh-ed25519 AAAAnew'] && strlen((string) $request['root_pass']) === 40
            && $request['metadata']['user_data'] === base64_encode("#!/bin/sh\necho hi") && $request->header('Authorization') === ['Bearer linode-secret']);
        $this->assertSame(CloudServerData::READINESS_READY, $linode->server(501)->readiness);
    }

    /**
     * Check the catalog keeps Metadata regions, instance classes and cloud-init Ubuntu images, and prices come from
     * the type's monthly price.
     *
     * @return void
     */
    public function test_linode_catalog_and_pricing(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://api.linode.com/v4/regions*' => Http::response(['data' => [
                ['id' => 'eu-west', 'label' => 'London', 'country' => 'gb', 'capabilities' => ['Linodes', 'Metadata'], 'status' => 'ok'],
                ['id' => 'ap-old', 'label' => 'Old', 'country' => 'jp', 'capabilities' => ['Linodes'], 'status' => 'ok'],
            ]]),
            'https://api.linode.com/v4/linode/types*' => Http::response(['data' => [
                ['id' => 'g6-standard-1', 'label' => 'Linode 2GB', 'class' => 'standard', 'memory' => 2048, 'vcpus' => 1, 'price' => ['monthly' => 12.0]],
                ['id' => 'g1-gpu-rtx6000-1', 'label' => 'GPU', 'class' => 'gpu', 'memory' => 32768, 'vcpus' => 8, 'price' => ['monthly' => 1000.0]],
            ]]),
            'https://api.linode.com/v4/images*' => Http::response(['data' => [
                ['id' => 'linode/ubuntu24.04', 'label' => 'Ubuntu 24.04 LTS', 'is_public' => true, 'capabilities' => ['cloud-init']],
                ['id' => 'linode/debian12', 'label' => 'Debian 12', 'is_public' => true, 'capabilities' => ['cloud-init']],
                ['id' => 'linode/ubuntu16.04lts', 'label' => 'Ubuntu 16.04', 'is_public' => true, 'capabilities' => []],
            ]]),
        ]);
        $linode = new Linode('linode-secret');
        $provider = (new Provider)->forceFill(['type' => ProviderType::Linode]);
        $catalog = app(ServerCatalog::class)->for($provider, $linode);

        $this->assertSame([['id' => 'eu-west', 'label' => 'London (GB)']], $catalog['regions']);
        $this->assertSame([['id' => 'g6-standard-1', 'label' => 'Linode 2GB · 2 GB RAM · 1 vCPU · $12/month']], $catalog['sizes']);
        $this->assertSame([['id' => 'linode/ubuntu24.04', 'label' => 'Ubuntu 24.04 LTS']], $catalog['images']);
        $this->assertSame(12.0, app(ServerPricing::class)->price(ProviderType::Linode, $linode->sizes(), 'g6-standard-1', 'eu-west'));
    }
}
