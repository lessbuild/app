<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use App\Data\Infrastructure\CloudServerData;
use App\Enums\ProviderType;
use App\Models\Provider;
use App\Services\Infrastructure\Lightsail;
use App\Services\Infrastructure\ServerCatalog;
use App\Services\Infrastructure\ServerPricing;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class LightsailClientTest extends TestCase
{
    /**
     * Check that Lightsail calls are signed for the instance's region, the SSH key is added by the launch script
     * ahead of the provisioning script, HTTPS is opened once the instance runs, and deletes treat a missing instance
     * as gone.
     *
     * @return void
     */
    public function test_lightsail_creates_and_reads_instances(): void
    {
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            $target = $request->header('X-Amz-Target')[0] ?? '';

            return match ($target) {
                'Lightsail_20161128.CreateInstances' => Http::response(['operations' => [['status' => 'Started']]]),
                'Lightsail_20161128.GetInstance' => Http::response(['instance' => ['name' => 'shop-web-1', 'state' => ['name' => 'running'], 'publicIpAddress' => '18.130.1.2', 'privateIpAddress' => '172.26.0.5', 'bundleId' => 'small_3_0', 'blueprintId' => 'ubuntu_24_04', 'location' => ['availabilityZone' => 'eu-west-2a']]]),
                'Lightsail_20161128.OpenInstancePublicPorts' => Http::response(['operation' => ['status' => 'Succeeded']]),
                'Lightsail_20161128.DeleteInstance' => Http::response(['__type' => 'NotFoundException'], 400),
                default => Http::response([], 500),
            };
        });
        $lightsail = new Lightsail('AKIAIOSFODNN7EXAMPLE:wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY');
        $key = $lightsail->createSshKey('managed', 'ssh-rsa AAAAB3NzaC1yc2E managed@buildpusher');

        $created = $lightsail->createServer(['name' => 'shop web 1', 'region' => 'eu-west-2a', 'size' => 'small_3_0', 'image' => 'ubuntu_24_04', 'ssh_keys' => [$key->fingerprint], 'user_data' => "#!/bin/sh\necho provisioning"]);
        $this->assertSame(['eu-west-2/shop-web-1', CloudServerData::READINESS_NOT_READY], [$created->identifier, $created->readiness]);
        Http::assertSent(function (Request $request): bool {
            if (($request->header('X-Amz-Target')[0] ?? '') !== 'Lightsail_20161128.CreateInstances') {
                return false;
            }
            $launch = (string) $request['userData'];

            return $request->url() === 'https://lightsail.eu-west-2.amazonaws.com/'
                && str_starts_with($request->header('Authorization')[0], 'AWS4-HMAC-SHA256 Credential=AKIAIOSFODNN7EXAMPLE/')
                && str_contains($request->header('Authorization')[0], '/eu-west-2/lightsail/aws4_request')
                && $request['availabilityZone'] === 'eu-west-2a' && $request['bundleId'] === 'small_3_0'
                && str_contains($launch, "echo 'ssh-rsa AAAAB3NzaC1yc2E managed@buildpusher' >> /root/.ssh/authorized_keys")
                && strpos($launch, 'authorized_keys') < strpos($launch, 'echo provisioning') && substr_count($launch, '#!') === 1;
        });

        $server = $lightsail->server('eu-west-2/shop-web-1');
        $this->assertSame([CloudServerData::READINESS_READY, '18.130.1.2', 'eu-west-2a'], [$server->readiness, $server->publicIp, $server->region]);
        Http::assertSent(fn (Request $request): bool => ($request->header('X-Amz-Target')[0] ?? '') === 'Lightsail_20161128.OpenInstancePublicPorts' && $request['portInfo']['fromPort'] === 443);
        $this->assertTrue($lightsail->deleteServer('eu-west-2/shop-web-1'));
    }

    /**
     * Check the catalog lists available zones, active Linux bundles and Ubuntu blueprints, with bundle prices.
     *
     * @return void
     */
    public function test_lightsail_catalog_and_pricing(): void
    {
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            return match ($request->header('X-Amz-Target')[0] ?? '') {
                'Lightsail_20161128.GetRegions' => Http::response(['regions' => [['name' => 'eu-west-2', 'displayName' => 'London', 'availabilityZones' => [['zoneName' => 'eu-west-2a', 'state' => 'available'], ['zoneName' => 'eu-west-2c', 'state' => 'impaired']]]]]),
                'Lightsail_20161128.GetBundles' => Http::response(['bundles' => [
                    ['bundleId' => 'small_3_0', 'name' => 'Small', 'price' => 10.0, 'ramSizeInGb' => 2.0, 'cpuCount' => 2, 'isActive' => true, 'supportedPlatforms' => ['LINUX_UNIX']],
                    ['bundleId' => 'small_win_3_0', 'name' => 'Small Windows', 'price' => 20.0, 'ramSizeInGb' => 2.0, 'cpuCount' => 2, 'isActive' => true, 'supportedPlatforms' => ['WINDOWS']],
                ]]),
                'Lightsail_20161128.GetBlueprints' => Http::response(['blueprints' => [
                    ['blueprintId' => 'ubuntu_24_04', 'name' => 'Ubuntu', 'version' => '24.04 LTS', 'type' => 'os', 'platform' => 'LINUX_UNIX', 'isActive' => true],
                    ['blueprintId' => 'wordpress', 'name' => 'WordPress', 'type' => 'app', 'platform' => 'LINUX_UNIX', 'isActive' => true],
                ]]),
                default => Http::response([], 500),
            };
        });
        $lightsail = new Lightsail('AKIAIOSFODNN7EXAMPLE:secretsecretsecretsecretsecret');
        $catalog = app(ServerCatalog::class)->for((new Provider)->forceFill(['type' => ProviderType::Lightsail]), $lightsail);

        $this->assertSame([['id' => 'eu-west-2a', 'label' => 'London (eu-west-2a)']], $catalog['regions']);
        $this->assertSame([['id' => 'small_3_0', 'label' => 'Small · 2 GB RAM · 2 vCPU · $10/month', 'price' => 10.0, 'currency' => 'USD']], $catalog['sizes']);
        $this->assertSame([['id' => 'ubuntu_24_04', 'label' => 'Ubuntu 24.04 LTS']], $catalog['images']);
        $this->assertSame(10.0, app(ServerPricing::class)->price(ProviderType::Lightsail, $lightsail->sizes(), 'small_3_0', 'eu-west-2a'));
    }
}
