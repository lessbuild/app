<?php

declare(strict_types=1);

namespace App\Services\Infrastructure;

use App\Contracts\Infrastructure\ServerProvider;
use App\Contracts\Infrastructure\SnapshotsServers;
use App\Data\Infrastructure\CloudServerData;
use App\Data\Infrastructure\CloudSshKeyData;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Azure virtual machines, with a service principal (an app registration's client secret, given Contributor on the
 * subscription). Each server gets its own resource group, created by one template deployment: a virtual network,
 * a network security group for SSH, HTTP and HTTPS, a static public address, a network interface and the VM, with a
 * 32 GB Standard SSD and Canonical's Ubuntu image. Deleting the server deletes the resource group, so nothing is
 * left behind. The SSH key and provisioning script go in as cloud-init custom data. A VM's ID is
 * "resource-group/name". Prices are pay-as-you-go prices in East US with the disk and address.
 */
class Azure implements ServerProvider, SnapshotsServers
{
    /** The Azure Resource Manager API. */
    private const string API = 'https://management.azure.com';

    /** The resource groups' API version. */
    private const string RESOURCES_VERSION = '2021-04-01';

    /** The compute API version. */
    private const string COMPUTE_VERSION = '2024-03-01';

    /** The network API version. */
    private const string NETWORK_VERSION = '2023-09-01';

    /** The name of the template deployment that builds a server. */
    private const string DEPLOYMENT = 'buildpusher-server';

    /** The VM's login user; root's keys come from the launch script. */
    private const string ADMIN_USER = 'buildpusher';

    /**
     * The VM sizes offered, with vCPUs, memory (MB) and the pay-as-you-go Linux price a month in East US plus the
     * 32 GB Standard SSD and static address.
     *
     * @var array<string, array{vcpus: int, memory: int, price: float}>
     */
    private const array SIZES = [
        'Standard_B1s' => ['vcpus' => 1, 'memory' => 1024, 'price' => 13.64],
        'Standard_B1ms' => ['vcpus' => 1, 'memory' => 2048, 'price' => 21.16],
        'Standard_B2s' => ['vcpus' => 2, 'memory' => 4096, 'price' => 36.42],
        'Standard_B2ms' => ['vcpus' => 2, 'memory' => 8192, 'price' => 66.79],
        'Standard_B4ms' => ['vcpus' => 4, 'memory' => 16384, 'price' => 127.52],
        'Standard_D2s_v5' => ['vcpus' => 2, 'memory' => 8192, 'price' => 76.13],
        'Standard_D4s_v5' => ['vcpus' => 4, 'memory' => 16384, 'price' => 146.21],
        'Standard_E2s_v5' => ['vcpus' => 2, 'memory' => 16384, 'price' => 98.03],
    ];

    /**
     * The Ubuntu releases offered, by Canonical's marketplace offer and SKU.
     *
     * @var array<string, array{name: string, offer: string, sku: string}>
     */
    private const array IMAGES = [
        'ubuntu-24.04' => ['name' => '24.04 LTS', 'offer' => 'ubuntu-24_04-lts', 'sku' => 'server'],
        'ubuntu-22.04' => ['name' => '22.04 LTS', 'offer' => '0001-com-ubuntu-server-jammy', 'sku' => '22_04-lts-gen2'],
    ];

    /**
     * The directory (tenant) ID.
     *
     * @var string
     */
    private readonly string $tenant;

    /**
     * The application (client) ID.
     *
     * @var string
     */
    private readonly string $clientId;

    /**
     * The subscription servers are created in.
     *
     * @var string
     */
    private readonly string $subscription;

    /**
     * The client secret.
     *
     * @var string
     */
    private readonly string $secret;

    /**
     * Create a new Azure instance.
     *
     * @param  string  $token  "TENANT_ID:CLIENT_ID:SUBSCRIPTION_ID:CLIENT_SECRET"
     */
    public function __construct(string $token)
    {
        [$tenant, $clientId, $subscription, $secret] = array_pad(explode(':', trim($token), 4), 4, '');
        $this->tenant = $tenant;
        $this->clientId = $clientId;
        $this->subscription = $subscription;
        $this->secret = $secret;
    }

    /**
     * Determine whether a credential has the shape TENANT_ID:CLIENT_ID:SUBSCRIPTION_ID:CLIENT_SECRET.
     *
     * @param  string  $token
     * @return bool
     */
    public static function validCredential(string $token): bool
    {
        $uuid = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

        return preg_match("/\\A{$uuid}:{$uuid}:{$uuid}:\\S{8,}\\z/i", trim($token)) === 1;
    }

    /**
     * Get the provider's name.
     *
     * @return string
     */
    public function name(): string
    {
        return 'Microsoft Azure';
    }

    /**
     * Keep the public key to add at launch; Azure takes it with the VM. The "fingerprint" carries the key.
     *
     * @param  string  $name
     * @param  string  $publicKey
     * @return CloudSshKeyData
     */
    public function createSshKey(string $name, string $publicKey): CloudSshKeyData
    {
        return new CloudSshKeyData('azure:'.base64_encode(trim($publicKey)), false);
    }

    /**
     * Nothing to remove at Azure, since keys aren't registered there.
     *
     * @param  string  $fingerprint
     * @return bool
     */
    public function deleteSshKey(string $fingerprint): bool
    {
        return true;
    }

    /**
     * Create the server's resource group in a region and start the deployment that builds the VM and its network.
     *
     * @param  array{name: string, region: string, size: string, image: int|string, ssh_keys?: list<int|string>, user_data?: string|null}  $parameters
     * @return CloudServerData
     */
    public function createServer(array $parameters): CloudServerData
    {
        $location = $this->location($parameters['region']);
        $image = self::IMAGES[(string) $parameters['image']] ?? throw new RuntimeException('Choose Ubuntu 24.04 or 22.04.');
        $name = $this->vmName($parameters['name']);
        $group = 'buildpusher-'.$name;
        $keys = [];
        foreach ($parameters['ssh_keys'] ?? [] as $key) {
            $material = base64_decode(substr((string) $key, strlen('azure:')), true);
            if (is_string($material) && preg_match('/\A(ssh-rsa|ssh-ed25519|ecdsa-sha2-nistp256) [A-Za-z0-9+\/=]+( [^\n]*)?\z/', $material) === 1) {
                $keys[] = $material;
            }
        }
        if ($keys === []) {
            throw new RuntimeException('Azure needs an SSH key for the VM.');
        }
        $script = (string) ($parameters['user_data'] ?? '');
        $rootKeys = implode('', array_map(fn (string $key): string => 'echo '.escapeshellarg($key)." >> /root/.ssh/authorized_keys\n", $keys));
        $launch = "#!/bin/bash\nmkdir -p /root/.ssh && chmod 700 /root/.ssh\n{$rootKeys}chmod 600 /root/.ssh/authorized_keys\n"
            ."sed -i 's/^#\\?PermitRootLogin .*/PermitRootLogin prohibit-password/' /etc/ssh/sshd_config && (systemctl reload ssh || systemctl reload sshd || true)\n"
            .(str_starts_with($script, '#!') ? substr($script, (int) strpos($script, "\n") + 1) : $script);

        $this->ok($this->client()->put($this->group($group), ['location' => $location, 'tags' => ['managed-by' => 'buildpusher']]), 'resource group creation');
        $this->ok($this->client()->put($this->group($group).'/providers/Microsoft.Resources/deployments/'.self::DEPLOYMENT.'?api-version='.self::RESOURCES_VERSION, [
            'properties' => ['mode' => 'Incremental', 'template' => $this->template($name, $location, $parameters['size'], $image, $keys[0], $launch)],
        ]), 'deployment');

        return new CloudServerData(
            identifier: "{$group}/{$name}", name: $name, region: $location, size: $parameters['size'], image: (string) $parameters['image'],
            publicIp: null, privateIp: null, providerStatus: 'deploying', readiness: CloudServerData::READINESS_NOT_READY,
        );
    }

    /**
     * Look up a VM with its addresses; while the deployment is still running it isn't ready, and a failed deployment
     * is reported.
     *
     * @param  int|string  $identifier  "resource-group/name"
     * @return CloudServerData
     */
    public function server(int|string $identifier): CloudServerData
    {
        [$group, $name] = $this->split((string) $identifier);
        $client = $this->client();
        $vm = $client->get($this->group($group)."/providers/Microsoft.Compute/virtualMachines/{$name}", ['api-version' => self::COMPUTE_VERSION, '$expand' => 'instanceView']);
        if ($vm->status() === 404) {
            $deployment = $client->get($this->group($group).'/providers/Microsoft.Resources/deployments/'.self::DEPLOYMENT, ['api-version' => self::RESOURCES_VERSION]);
            if ($deployment->json('properties.provisioningState') === 'Failed') {
                $error = $deployment->json('properties.error.details.0.message') ?? $deployment->json('properties.error.message');

                throw new RuntimeException('The Azure deployment failed'.(is_string($error) ? ': '.mb_substr($error, 0, 300) : '.'));
            }

            return new CloudServerData(
                identifier: "{$group}/{$name}", name: $name, region: '', size: '', image: '',
                publicIp: null, privateIp: null, providerStatus: 'deploying', readiness: CloudServerData::READINESS_NOT_READY,
            );
        }
        $this->ok($vm, 'VM lookup');
        $power = null;
        foreach ((array) $vm->json('properties.instanceView.statuses', []) as $status) {
            if (is_array($status) && is_string($status['code'] ?? null) && str_starts_with($status['code'], 'PowerState/')) {
                $power = substr($status['code'], strlen('PowerState/'));
            }
        }
        $public = $client->get($this->group($group)."/providers/Microsoft.Network/publicIPAddresses/{$name}-ip", ['api-version' => self::NETWORK_VERSION])->json('properties.ipAddress');
        $private = $client->get($this->group($group)."/providers/Microsoft.Network/networkInterfaces/{$name}-nic", ['api-version' => self::NETWORK_VERSION])->json('properties.ipConfigurations.0.properties.privateIPAddress');
        $public = is_string($public) ? $public : null;

        return new CloudServerData(
            identifier: "{$group}/{$name}", name: $name, region: (string) $vm->json('location', ''), size: (string) $vm->json('properties.hardwareProfile.vmSize', ''), image: '',
            publicIp: $public, privateIp: is_string($private) ? $private : null, providerStatus: $power,
            readiness: $power === null ? CloudServerData::READINESS_NOT_READY
                : ($power === 'running' && $public !== null ? CloudServerData::READINESS_READY : CloudServerData::READINESS_NOT_READY),
        );
    }

    /**
     * Delete the server's resource group with everything in it; a missing one counts as deleted.
     *
     * @param  int|string  $identifier
     * @return bool
     */
    public function deleteServer(int|string $identifier): bool
    {
        [$group] = $this->split((string) $identifier);
        $response = $this->client()->delete($this->group($group).'?api-version='.self::RESOURCES_VERSION);

        return $response->successful() || $response->status() === 404;
    }

    /**
     * List the subscription's physical regions, DigitalOcean-shaped.
     *
     * @return list<array<string, mixed>>
     */
    public function regions(): array
    {
        $regions = [];
        $response = $this->ok($this->client()->get(self::API."/subscriptions/{$this->subscription}/locations", ['api-version' => '2022-12-01']), 'region listing');
        foreach ((array) $response->json('value', []) as $location) {
            if (is_array($location) && is_string($location['name'] ?? null) && ($location['metadata']['regionType'] ?? 'Physical') === 'Physical') {
                $regions[] = ['slug' => $location['name'], 'name' => (string) ($location['displayName'] ?? $location['name']), 'available' => true];
            }
        }

        return $regions;
    }

    /**
     * List the VM sizes offered, DigitalOcean-shaped.
     *
     * @return list<array<string, mixed>>
     */
    public function sizes(): array
    {
        $sizes = [];
        foreach (self::SIZES as $size => $spec) {
            $sizes[] = ['slug' => $size, 'description' => str_replace('Standard_', '', $size), 'memory' => $spec['memory'], 'vcpus' => $spec['vcpus'], 'price_monthly' => $spec['price']];
        }

        return $sizes;
    }

    /**
     * List the Ubuntu releases offered, DigitalOcean-shaped.
     *
     * @return list<array<string, mixed>>
     */
    public function images(): array
    {
        $images = [];
        foreach (self::IMAGES as $slug => $image) {
            $images[] = ['slug' => $slug, 'distribution' => 'Ubuntu', 'name' => $image['name']];
        }

        return $images;
    }

    /**
     * Make an authenticated read-only call that checks the credential works, for the provider's connection test.
     *
     * @return Response
     */
    public function ping(): Response
    {
        return $this->client()->get(self::API."/subscriptions/{$this->subscription}", ['api-version' => '2022-12-01']);
    }

    /**
     * Snapshot the VM's OS disk into its resource group; returns it as "resource-group/snapshot-name".
     *
     * @param  int|string  $identifier
     * @param  string  $name
     * @return string
     */
    public function snapshotServer(int|string $identifier, string $name): string
    {
        [$group, $vmName] = $this->split((string) $identifier);
        $vm = $this->ok($this->client()->get($this->group($group)."/providers/Microsoft.Compute/virtualMachines/{$vmName}", ['api-version' => self::COMPUTE_VERSION]), 'VM lookup');
        $disk = $vm->json('properties.storageProfile.osDisk.managedDisk.id');
        if (! is_string($disk)) {
            throw new RuntimeException('The VM has no managed OS disk to snapshot.');
        }
        $snapshot = substr($this->vmName($vmName.'-'.$name), 0, 60).'-'.now()->format('YmdHis');
        $this->ok($this->client()->put($this->group($group)."/providers/Microsoft.Compute/snapshots/{$snapshot}?api-version=".self::COMPUTE_VERSION, [
            'location' => $vm->json('location'), 'properties' => ['creationData' => ['createOption' => 'Copy', 'sourceResourceId' => $disk], 'incremental' => true],
        ]), 'snapshot');

        return "{$group}/{$snapshot}";
    }

    /**
     * Delete a disk snapshot.
     *
     * @param  string  $snapshot  "resource-group/snapshot-name"
     * @return bool
     */
    public function deleteSnapshot(string $snapshot): bool
    {
        [$group, $name] = $this->split($snapshot);
        $response = $this->client()->delete($this->group($group)."/providers/Microsoft.Compute/snapshots/{$name}?api-version=".self::COMPUTE_VERSION);

        return $response->successful() || $response->status() === 404;
    }

    /**
     * Build the deployment template for a VM with its network.
     *
     * @param  string  $name
     * @param  string  $location
     * @param  string  $size
     * @param  array{name: string, offer: string, sku: string}  $image
     * @param  string  $sshKey  The admin user's public key.
     * @param  string  $launch  The cloud-init script.
     * @return array<string, mixed>
     */
    private function template(string $name, string $location, string $size, array $image, string $sshKey, string $launch): array
    {
        $network = self::NETWORK_VERSION;
        $id = fn (string $type, string $resource): string => "[resourceId('{$type}', '{$resource}')]";
        $rules = [];
        foreach (['ssh' => 22, 'http' => 80, 'https' => 443] as $rule => $port) {
            $rules[] = ['name' => $rule, 'properties' => [
                'priority' => 100 + count($rules) * 10, 'direction' => 'Inbound', 'access' => 'Allow', 'protocol' => 'Tcp',
                'sourceAddressPrefix' => '*', 'sourcePortRange' => '*', 'destinationAddressPrefix' => '*', 'destinationPortRange' => (string) $port,
            ]];
        }

        return [
            '$schema' => 'https://schema.management.azure.com/schemas/2019-04-01/deploymentTemplate.json#',
            'contentVersion' => '1.0.0.0',
            'resources' => [
                ['type' => 'Microsoft.Network/networkSecurityGroups', 'apiVersion' => $network, 'name' => "{$name}-nsg", 'location' => $location, 'properties' => ['securityRules' => $rules]],
                ['type' => 'Microsoft.Network/virtualNetworks', 'apiVersion' => $network, 'name' => "{$name}-vnet", 'location' => $location,
                    'dependsOn' => [$id('Microsoft.Network/networkSecurityGroups', "{$name}-nsg")],
                    'properties' => ['addressSpace' => ['addressPrefixes' => ['10.0.0.0/16']], 'subnets' => [['name' => 'default', 'properties' => [
                        'addressPrefix' => '10.0.0.0/24', 'networkSecurityGroup' => ['id' => $id('Microsoft.Network/networkSecurityGroups', "{$name}-nsg")],
                    ]]]]],
                ['type' => 'Microsoft.Network/publicIPAddresses', 'apiVersion' => $network, 'name' => "{$name}-ip", 'location' => $location,
                    'sku' => ['name' => 'Standard'], 'properties' => ['publicIPAllocationMethod' => 'Static']],
                ['type' => 'Microsoft.Network/networkInterfaces', 'apiVersion' => $network, 'name' => "{$name}-nic", 'location' => $location,
                    'dependsOn' => [$id('Microsoft.Network/virtualNetworks', "{$name}-vnet"), $id('Microsoft.Network/publicIPAddresses', "{$name}-ip")],
                    'properties' => ['ipConfigurations' => [['name' => 'primary', 'properties' => [
                        'subnet' => ['id' => "[resourceId('Microsoft.Network/virtualNetworks/subnets', '{$name}-vnet', 'default')]"],
                        'publicIPAddress' => ['id' => $id('Microsoft.Network/publicIPAddresses', "{$name}-ip")],
                    ]]]]],
                ['type' => 'Microsoft.Compute/virtualMachines', 'apiVersion' => self::COMPUTE_VERSION, 'name' => $name, 'location' => $location,
                    'dependsOn' => [$id('Microsoft.Network/networkInterfaces', "{$name}-nic")],
                    'properties' => [
                        'hardwareProfile' => ['vmSize' => $size],
                        'storageProfile' => [
                            'imageReference' => ['publisher' => 'Canonical', 'offer' => $image['offer'], 'sku' => $image['sku'], 'version' => 'latest'],
                            'osDisk' => ['createOption' => 'FromImage', 'diskSizeGB' => 32, 'deleteOption' => 'Delete', 'managedDisk' => ['storageAccountType' => 'StandardSSD_LRS']],
                        ],
                        'osProfile' => [
                            'computerName' => $name, 'adminUsername' => self::ADMIN_USER, 'customData' => base64_encode($launch),
                            'linuxConfiguration' => ['disablePasswordAuthentication' => true, 'ssh' => ['publicKeys' => [
                                ['path' => '/home/'.self::ADMIN_USER.'/.ssh/authorized_keys', 'keyData' => $sshKey],
                            ]]],
                        ],
                        'networkProfile' => ['networkInterfaces' => [['id' => $id('Microsoft.Network/networkInterfaces', "{$name}-nic"), 'properties' => ['deleteOption' => 'Delete']]]],
                    ]],
            ],
        ];
    }

    /**
     * Build an authenticated JSON client, with an access token cached for 50 minutes.
     *
     * @return PendingRequest
     */
    private function client(): PendingRequest
    {
        $token = Cache::remember('azure.token.'.sha1($this->tenant.$this->clientId.$this->secret), now()->addMinutes(50), fn (): string => $this->accessToken());

        return Http::withToken($token)->acceptJson()->asJson()->withUserAgent('BuildPusher')->connectTimeout(5)->timeout(30);
    }

    /**
     * Get an access token to Azure Resource Manager with the client secret.
     *
     * @return string
     */
    private function accessToken(): string
    {
        if (preg_match('/\A[0-9a-f-]{36}\z/i', $this->tenant) !== 1) {
            throw new RuntimeException('That isn’t an Azure tenant ID.');
        }
        $response = Http::asForm()->connectTimeout(5)->timeout(15)->post("https://login.microsoftonline.com/{$this->tenant}/oauth2/v2.0/token", [
            'grant_type' => 'client_credentials', 'client_id' => $this->clientId, 'client_secret' => $this->secret,
            'scope' => 'https://management.azure.com/.default',
        ]);
        $token = $response->json('access_token');
        if (! $response->successful() || ! is_string($token)) {
            throw new RuntimeException("Azure refused the service principal (HTTP {$response->status()}).");
        }

        return $token;
    }

    /**
     * Get a resource group's URL.
     *
     * @param  string  $group
     * @return string
     */
    private function group(string $group): string
    {
        return self::API."/subscriptions/{$this->subscription}/resourcegroups/{$group}";
    }

    /**
     * Check a region name's shape before it goes into a request.
     *
     * @param  string  $location
     * @return string
     */
    private function location(string $location): string
    {
        return preg_match('/\A[a-z0-9]+\z/', $location) === 1 ? $location : throw new RuntimeException('That isn’t an Azure region.');
    }

    /**
     * Make a valid VM name: letters, digits and hyphens, starting with a letter, at most 60.
     *
     * @param  string  $name
     * @return string
     */
    private function vmName(string $name): string
    {
        $clean = trim((string) preg_replace('/[^a-z0-9-]+/', '-', strtolower($name)), '-');
        $clean = preg_match('/\A[a-z]/', $clean) === 1 ? $clean : 'bp-'.$clean;

        return rtrim(substr($clean, 0, 60), '-');
    }

    /**
     * Split an ID into its resource group and resource name.
     *
     * @param  string  $identifier
     * @return array{0: string, 1: string}
     */
    private function split(string $identifier): array
    {
        [$group, $name] = array_pad(explode('/', $identifier, 2), 2, '');
        if (preg_match('/\A[A-Za-z0-9._()-]{1,90}\z/', $group) !== 1 || preg_match('/\A[A-Za-z0-9._-]{1,80}\z/', $name) !== 1) {
            throw new RuntimeException('That isn’t an Azure server ID.');
        }

        return [$group, $name];
    }

    /**
     * Return the response when it succeeded, or throw without exposing it.
     *
     * @param  Response  $response
     * @param  string  $operation
     * @return Response
     */
    private function ok(Response $response, string $operation): Response
    {
        if (! $response->successful()) {
            $code = $response->json('error.code');

            throw new RuntimeException("Azure {$operation} failed with HTTP {$response->status()}".(is_string($code) ? " ({$code})" : '').'.');
        }

        return $response;
    }
}
