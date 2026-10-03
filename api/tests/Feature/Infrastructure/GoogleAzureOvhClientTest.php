<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use App\Data\Infrastructure\CloudServerData;
use App\Enums\ProviderType;
use App\Models\Project;
use App\Models\Provider;
use App\Services\Infrastructure\Azure;
use App\Services\Infrastructure\GoogleCompute;
use App\Services\Infrastructure\Ovh;
use App\Services\Infrastructure\ServerCatalog;
use App\Services\Infrastructure\ServerPricing;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class GoogleAzureOvhClientTest extends TestCase
{
    use MonitoringHelpers;
    use RefreshDatabase;

    /**
     * Make a service account key with a fresh RSA key.
     *
     * @return string
     */
    private function serviceAccountKey(): string
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->assertNotFalse($key);
        openssl_pkey_export($key, $pem);

        return (string) json_encode(['type' => 'service_account', 'project_id' => 'shop-prod-123', 'client_email' => 'deployer@shop-prod-123.iam.gserviceaccount.com', 'private_key' => $pem, 'token_uri' => 'https://oauth2.googleapis.com/token']);
    }

    /**
     * Check a Compute Engine VM is created after the firewall rule, with a signed token, Ubuntu's image family and
     * the SSH key in its cloud-init user data, and is looked up and deleted as zone/name.
     *
     * @return void
     */
    public function test_google_compute_engine_creates_looks_up_and_deletes_vms(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.token', 'expires_in' => 3600]),
            'compute.googleapis.com/compute/v1/projects/shop-prod-123/global/firewalls/buildpusher-web' => Http::response(['error' => ['code' => 404]], 404),
            'compute.googleapis.com/compute/v1/projects/shop-prod-123/global/firewalls' => Http::response(['name' => 'op-1']),
            'compute.googleapis.com/compute/v1/projects/shop-prod-123/zones/europe-west2-a/instances/web-1' => Http::sequence()
                ->push(['status' => 'RUNNING', 'machineType' => 'https://x/zones/europe-west2-a/machineTypes/e2-small', 'networkInterfaces' => [['networkIP' => '10.154.0.2', 'accessConfigs' => [['natIP' => '34.89.1.2']]]]])
                ->push(['name' => 'op-2']),
            'compute.googleapis.com/compute/v1/projects/shop-prod-123/zones/europe-west2-a/instances' => Http::response(['name' => 'op-3']),
        ]);
        $key = $this->serviceAccountKey();
        $this->assertTrue(GoogleCompute::validKey($key));
        $this->assertFalse(GoogleCompute::validKey('{"type":"authorized_user"}'));
        $client = new GoogleCompute($key);

        $created = $client->createServer(['name' => 'Web 1', 'region' => 'europe-west2-a', 'size' => 'e2-small', 'image' => 'ubuntu-24.04', 'ssh_keys' => [$client->createSshKey('web', 'ssh-ed25519 AAAAKEY')->fingerprint], 'user_data' => "#!/bin/bash\necho provision"]);

        $this->assertSame('europe-west2-a/web-1', $created->identifier);
        Http::assertSent(function (Request $request): bool {
            if ($request->url() !== 'https://oauth2.googleapis.com/token') {
                return false;
            }
            [, $claims] = explode('.', (string) $request['assertion']);
            $claims = json_decode((string) base64_decode(strtr($claims, '-_', '+/')), true);

            return $request['grant_type'] === 'urn:ietf:params:oauth:grant-type:jwt-bearer' && $claims['iss'] === 'deployer@shop-prod-123.iam.gserviceaccount.com' && $claims['scope'] === 'https://www.googleapis.com/auth/compute';
        });
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/global/firewalls') && $request['allowed'][0]['ports'] === ['22', '80', '443'] && $request['targetTags'] === ['buildpusher']);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/zones/europe-west2-a/instances') && $request->hasHeader('Authorization', 'Bearer ya29.token')
            && $request['disks'][0]['initializeParams']['sourceImage'] === 'projects/ubuntu-os-cloud/global/images/family/ubuntu-2404-lts-amd64'
            && str_contains($request['metadata']['items'][0]['value'], "echo 'ssh-ed25519 AAAAKEY' >> /root/.ssh/authorized_keys") && $request['metadata']['items'][0]['key'] === 'user-data');

        $found = $client->server('europe-west2-a/web-1');
        $this->assertSame(['34.89.1.2', '10.154.0.2', 'e2-small', CloudServerData::READINESS_READY], [$found->publicIp, $found->privateIp, $found->size, $found->readiness]);
        $this->assertTrue($client->deleteServer('europe-west2-a/web-1'));
        $this->assertSame(14.73, app(ServerPricing::class)->price(ProviderType::GoogleCompute, $client->sizes(), 'e2-small', 'europe-west2-a'));
    }

    /**
     * Check an Azure server is a resource group with one template deployment (network, security group, address,
     * interface and VM with the SSH key and cloud-init), is looked up with its addresses, and is deleted with its
     * resource group.
     *
     * @return void
     */
    public function test_azure_deploys_looks_up_and_deletes_vms_in_their_own_resource_group(): void
    {
        $tenant = '11111111-1111-1111-1111-111111111111';
        $subscription = '33333333-3333-3333-3333-333333333333';
        $group = "management.azure.com/subscriptions/{$subscription}/resourcegroups/buildpusher-web-1";
        Http::fake([
            "login.microsoftonline.com/{$tenant}/oauth2/v2.0/token" => Http::response(['access_token' => 'azure-token']),
            "{$group}/providers/Microsoft.Resources/deployments/*" => Http::response(['properties' => ['provisioningState' => 'Accepted']], 201),
            "{$group}/providers/Microsoft.Compute/virtualMachines/web-1*" => Http::response(['location' => 'uksouth', 'properties' => ['hardwareProfile' => ['vmSize' => 'Standard_B2s'], 'instanceView' => ['statuses' => [['code' => 'ProvisioningState/succeeded'], ['code' => 'PowerState/running']]]]]),
            "{$group}/providers/Microsoft.Network/publicIPAddresses/web-1-ip*" => Http::response(['properties' => ['ipAddress' => '20.1.2.3']]),
            "{$group}/providers/Microsoft.Network/networkInterfaces/web-1-nic*" => Http::response(['properties' => ['ipConfigurations' => [['properties' => ['privateIPAddress' => '10.0.0.4']]]]]),
            "{$group}*" => Http::response(['name' => 'buildpusher-web-1'], 201),
        ]);
        $credential = "{$tenant}:22222222-2222-2222-2222-222222222222:{$subscription}:s3cr3t~value.with-chars";
        $this->assertTrue(Azure::validCredential($credential));
        $this->assertFalse(Azure::validCredential('tenant:client:secret'));
        $client = new Azure($credential);

        $created = $client->createServer(['name' => 'web-1', 'region' => 'uksouth', 'size' => 'Standard_B2s', 'image' => 'ubuntu-24.04', 'ssh_keys' => [$client->createSshKey('web', 'ssh-ed25519 AAAAKEY')->fingerprint], 'user_data' => "#!/bin/bash\necho provision"]);

        $this->assertSame('buildpusher-web-1/web-1', $created->identifier);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'login.microsoftonline.com') && $request['client_secret'] === 's3cr3t~value.with-chars' && $request['scope'] === 'https://management.azure.com/.default');
        Http::assertSent(function (Request $request): bool {
            if (! str_contains($request->url(), '/deployments/buildpusher-server')) {
                return false;
            }
            /** @var array{properties: array{template: array{resources: list<array{type: string, properties: array<string, mixed>}>}}} $body */
            $body = json_decode($request->body(), true);
            $resources = [];
            foreach ($body['properties']['template']['resources'] as $resource) {
                $resources[$resource['type']] = $resource['properties'];
            }
            /** @var array{storageProfile: array{imageReference: array{offer: string}}, osProfile: array{customData: string, linuxConfiguration: array{ssh: array{publicKeys: list<array{keyData: string}>}}}} $vm */
            $vm = $resources['Microsoft.Compute/virtualMachines'];
            /** @var array{securityRules: list<array{properties: array{destinationPortRange: string}}>} $security */
            $security = $resources['Microsoft.Network/networkSecurityGroups'];

            return count($resources) === 5 && $vm['storageProfile']['imageReference']['offer'] === 'ubuntu-24_04-lts'
                && $vm['osProfile']['linuxConfiguration']['ssh']['publicKeys'][0]['keyData'] === 'ssh-ed25519 AAAAKEY'
                && str_contains((string) base64_decode($vm['osProfile']['customData']), 'echo provision')
                && array_map(fn (array $rule): string => $rule['properties']['destinationPortRange'], $security['securityRules']) === ['22', '80', '443'];
        });

        $found = $client->server('buildpusher-web-1/web-1');
        $this->assertSame(['20.1.2.3', '10.0.0.4', 'Standard_B2s', 'running', CloudServerData::READINESS_READY], [$found->publicIp, $found->privateIp, $found->size, $found->providerStatus, $found->readiness]);
        $this->assertTrue($client->deleteServer('buildpusher-web-1/web-1'));
        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && str_ends_with(parse_url($request->url(), PHP_URL_PATH) ?: '', '/resourcegroups/buildpusher-web-1'));
    }

    /**
     * Check OVHcloud calls are signed, an instance is launched with the region's flavor and Ubuntu image, and sizes
     * are priced from the public catalogue in euros.
     *
     * @return void
     */
    public function test_ovhcloud_signs_calls_launches_instances_and_prices_flavors(): void
    {
        $project = str_repeat('a', 32);
        $base = "eu.api.ovh.com/1.0/cloud/project/{$project}";
        $id = '9f1c1f3e-1111-2222-3333-444455556666';
        Http::fake([
            'eu.api.ovh.com/1.0/auth/time' => Http::response((string) time()),
            'eu.api.ovh.com/1.0/order/catalog/public/cloud*' => Http::response(['addons' => [['planCode' => 'd2-4.consumption', 'pricings' => [['price' => 1550000]]]]]),
            "{$base}/flavor?region=GRA11" => Http::response([['id' => 'flavor-b', 'name' => 'b3-8'], ['id' => 'flavor-d', 'name' => 'd2-4']]),
            "{$base}/flavor" => Http::response([['id' => 'f1', 'name' => 'd2-4', 'osType' => 'linux', 'ram' => 4000, 'vcpus' => 2, 'planCodes' => ['hourly' => 'd2-4.consumption']], ['id' => 'f2', 'name' => 'd2-4', 'osType' => 'linux'], ['id' => 'f3', 'name' => 'win-b2-7', 'osType' => 'windows']]),
            "{$base}/image*" => Http::response([['id' => 'img-22', 'name' => 'Ubuntu 22.04'], ['id' => 'img-24', 'name' => 'Ubuntu 24.04']]),
            "{$base}/instance/{$id}" => Http::response(['id' => $id, 'name' => 'web-1', 'status' => 'ACTIVE', 'region' => 'GRA11', 'flavor' => ['name' => 'd2-4'], 'ipAddresses' => [['ip' => '51.75.1.2', 'type' => 'public', 'version' => 4], ['ip' => '10.0.0.5', 'type' => 'private', 'version' => 4]]]),
            "{$base}/instance" => Http::response(['id' => $id, 'status' => 'BUILD']),
        ]);
        $credential = 'eu:appkey123456:'.str_repeat('s', 32).':'.str_repeat('c', 32).":{$project}";
        $this->assertTrue(Ovh::validCredential($credential));
        $client = new Ovh($credential);

        $created = $client->createServer(['name' => 'web-1', 'region' => 'GRA11', 'size' => 'd2-4', 'image' => 'ubuntu-24.04', 'ssh_keys' => [$client->createSshKey('web', 'ssh-ed25519 AAAAKEY')->fingerprint], 'user_data' => "#!/bin/bash\necho provision"]);

        $this->assertSame($id, $created->identifier);
        Http::assertSent(function (Request $request) use ($base): bool {
            if ($request->url() !== "https://{$base}/instance") {
                return false;
            }
            $timestamp = $request->header('X-Ovh-Timestamp')[0];
            $expected = '$1$'.sha1(implode('+', [str_repeat('s', 32), str_repeat('c', 32), 'POST', $request->url(), $request->body(), $timestamp]));

            return $request->header('X-Ovh-Signature')[0] === $expected && $request['flavorId'] === 'flavor-d' && $request['imageId'] === 'img-24'
                && str_contains($request['userData'], "echo 'ssh-ed25519 AAAAKEY' >> /root/.ssh/authorized_keys") && $request['monthlyBilling'] === false;
        });

        $found = $client->server($id);
        $this->assertSame(['51.75.1.2', '10.0.0.5', CloudServerData::READINESS_READY], [$found->publicIp, $found->privateIp, $found->readiness]);
        $sizes = $client->sizes();
        $this->assertSame(['d2-4'], array_column($sizes, 'slug'));
        $this->assertSame(11.32, app(ServerPricing::class)->price(ProviderType::Ovh, $sizes, 'd2-4', 'GRA11'));
    }

    /**
     * Check each new cloud can be connected with its credential's shape and is offered for servers.
     *
     * @return void
     */
    public function test_the_new_clouds_are_connected_with_their_credentials(): void
    {
        $this->withoutMiddleware(RequirePassword::class);
        $project = Project::factory()->withServices(['infrastructure'])->create();
        $owner = $this->ownerOf($project);
        $account = $project->account;

        foreach ([
            ['ec2', 'not-a-key', 'AKIAEXAMPLEKEY12345:secretsecretsecretsecretsecret'],
            ['gce', '{"type":"authorized_user"}', $this->serviceAccountKey()],
            ['azure', 'tenant:client:secret', '11111111-1111-1111-1111-111111111111:22222222-2222-2222-2222-222222222222:33333333-3333-3333-3333-333333333333:secret-value'],
            ['ovh', 'eu:short', 'ca:appkey123456:'.str_repeat('s', 32).':'.str_repeat('c', 32).':'.str_repeat('b', 32)],
        ] as [$type, $bad, $good]) {
            $this->actingAs($owner)->post('/account/providers', ['name' => $type.' bad', 'type' => $type, 'token' => $bad])->assertSessionHasErrors('token');
            $this->actingAs($owner)->post('/account/providers', ['name' => $type, 'type' => $type, 'token' => $good])->assertSessionHasNoErrors();
        }
        $this->assertSame(4, Provider::query()->where('account_id', $account->id)->whereIn('type', ['ec2', 'gce', 'azure', 'ovh'])->count());
        $this->assertContains(ProviderType::Ovh, ProviderType::serverHosts());
        $this->assertSame('AWS EC2', ProviderType::Ec2->label());
        $this->assertInstanceOf(ServerCatalog::class, app(ServerCatalog::class));
    }
}
