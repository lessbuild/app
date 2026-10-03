<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use App\Data\Infrastructure\CloudServerData;
use App\Enums\ProviderType;
use App\Services\Infrastructure\Scaleway;
use App\Services\Infrastructure\ServerPricing;
use App\Services\Infrastructure\UpCloud;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

final class ScalewayUpCloudClientTest extends TestCase
{
    /**
     * Check a Scaleway server is created in the project with the zone's image for the label, gets its provisioning
     * script through cloud-init and is powered on, and is identified as zone/id from then on.
     *
     * @return void
     */
    public function test_scaleway_creates_looks_up_and_terminates_servers(): void
    {
        $project = '11111111-1111-1111-1111-111111111111';
        $secret = '22222222-2222-2222-2222-222222222222';
        $server = ['id' => 'abc', 'name' => 'web-1', 'commercial_type' => 'DEV1-S', 'state' => 'running', 'public_ip' => ['address' => '51.15.0.9'], 'private_ip' => null, 'image' => ['name' => 'Ubuntu 24.04']];
        Http::fake([
            'api.scaleway.com/marketplace/v2/local-images*' => Http::response(['local_images' => [['id' => 'img-gp', 'compatible_commercial_types' => ['GP1-S']], ['id' => 'img-dev', 'compatible_commercial_types' => ['DEV1-S']]]]),
            'api.scaleway.com/instance/v1/zones/nl-ams-1/servers/abc/user_data/cloud-init' => Http::response('', 204),
            'api.scaleway.com/instance/v1/zones/nl-ams-1/servers/abc/action' => Http::response(['task' => ['id' => 't']]),
            'api.scaleway.com/instance/v1/zones/nl-ams-1/servers/abc' => Http::response(['server' => $server]),
            'api.scaleway.com/instance/v1/zones/nl-ams-1/servers' => Http::response(['server' => [...$server, 'state' => 'stopped', 'public_ip' => null]], 201),
            'api.scaleway.com/instance/v1/zones/fr-par-1/products/servers*' => Http::response(['servers' => ['DEV1-S' => ['ncpus' => 2, 'ram' => 2147483648, 'monthly_price' => 6.42, 'arch' => 'x86_64'], 'COPARM1-2C-8G' => ['arch' => 'arm64']]]),
        ]);
        $client = new Scaleway("{$project}:{$secret}");

        $created = $client->createServer(['name' => 'web-1', 'region' => 'nl-ams-1', 'size' => 'DEV1-S', 'image' => 'ubuntu_noble', 'user_data' => "#!/bin/bash\necho hi"]);

        $this->assertSame(['nl-ams-1/abc', CloudServerData::READINESS_NOT_READY], [$created->identifier, $created->readiness]);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/zones/nl-ams-1/servers') && $request['image'] === 'img-dev' && $request['project'] === $project && $request->hasHeader('X-Auth-Token', $secret));
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/cloud-init') && $request->method() === 'PATCH' && $request->body() === "#!/bin/bash\necho hi");
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/abc/action') && $request['action'] === 'poweron');

        $found = $client->server('nl-ams-1/abc');
        $this->assertSame(['51.15.0.9', CloudServerData::READINESS_READY, 'DEV1-S'], [$found->publicIp, $found->readiness, $found->size]);
        $this->assertTrue($client->deleteServer('nl-ams-1/abc'));
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/abc/action') && $request['action'] === 'terminate');

        $sizes = $client->sizes();
        $this->assertSame([['slug' => 'DEV1-S', 'description' => 'DEV1-S', 'vcpus' => 2, 'memory' => 2048, 'price_monthly' => 6.42]], $sizes);
        $this->assertSame(6.42, app(ServerPricing::class)->price(ProviderType::Scaleway, $sizes, 'DEV1-S', 'nl-ams-1'));
    }

    /**
     * Check an UpCloud server is created from the template with the plan's disk, root's SSH key and cloud-init user
     * data, and that deleting stops it first and waits until it has stopped.
     *
     * @return void
     */
    public function test_upcloud_creates_and_deletes_servers(): void
    {
        Sleep::fake();
        $server = ['uuid' => 'u-1', 'hostname' => 'web-1', 'zone' => 'de-fra1', 'plan' => '1xCPU-2GB', 'state' => 'maintenance', 'ip_addresses' => ['ip_address' => [
            ['access' => 'public', 'family' => 'IPv4', 'address' => '94.237.0.9'], ['access' => 'utility', 'family' => 'IPv4', 'address' => '10.1.0.9'],
        ]]];
        Http::fake([
            'api.upcloud.com/1.3/plan' => Http::response(['plans' => ['plan' => [['name' => '1xCPU-2GB', 'core_number' => 1, 'memory_amount' => 2048, 'storage_size' => 50]]]]),
            'api.upcloud.com/1.3/price' => Http::response(['prices' => ['zone' => [['name' => 'de-fra1', 'server_plan_1xCPU-2GB' => ['amount' => 1, 'price' => 1.9345]]]]]),
            'api.upcloud.com/1.3/server' => Http::response(['server' => $server], 202),
            'api.upcloud.com/1.3/server/u-1/stop' => Http::response(['server' => $server], 202),
            'api.upcloud.com/1.3/server/u-1?*' => Http::response('', 204),
            'api.upcloud.com/1.3/server/u-1' => Http::sequence()->push(['server' => [...$server, 'state' => 'started']])->push(['server' => [...$server, 'state' => 'stopped']]),
        ]);
        $client = new UpCloud('api-user:pa:ss');
        $key = $client->createSshKey('web-1', "ssh-ed25519 AAAA web-1\n");
        $this->assertSame(['ssh-ed25519 AAAA web-1', false], [$key->fingerprint, $key->created]);

        $created = $client->createServer(['name' => 'web-1', 'region' => 'de-fra1', 'size' => '1xCPU-2GB', 'image' => 'tpl-uuid', 'ssh_keys' => [$key->fingerprint], 'user_data' => '#!/bin/bash']);

        $this->assertSame(['u-1', '94.237.0.9', '10.1.0.9', CloudServerData::READINESS_NOT_READY], [$created->identifier, $created->publicIp, $created->privateIp, $created->readiness]);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.upcloud.com/1.3/server' && $request->method() === 'POST'
            && $request['server']['storage_devices']['storage_device'][0]['size'] === 50 && $request['server']['storage_devices']['storage_device'][0]['storage'] === 'tpl-uuid'
            && $request['server']['login_user']['ssh_keys']['ssh_key'] === ['ssh-ed25519 AAAA web-1'] && $request['server']['user_data'] === '#!/bin/bash'
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('api-user:pa:ss')));
        $this->assertSame(13.0, $client->sizes()[0]['price_monthly']);

        $this->assertTrue($client->deleteServer('u-1'));
        Sleep::assertSleptTimes(1);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && str_contains($request->url(), 'storages=1'));
    }
}
