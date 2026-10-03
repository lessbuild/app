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
 * Google Compute Engine VMs, with a service account's JSON key (it signs a token request; the account needs the
 * Compute Instance Admin and Compute Security Admin roles). VMs go in the project's default network with a firewall
 * rule for SSH, HTTP and HTTPS on the "buildpusher" tag, a 25 GB balanced disk and Canonical's Ubuntu image. The SSH
 * key and provisioning script are passed as cloud-init user data, which Ubuntu runs once. A VM's ID is "zone/name".
 * Prices are on-demand prices in us-central1 with the disk.
 */
class GoogleCompute implements ServerProvider, SnapshotsServers
{
    /** The Compute Engine API. */
    private const string API = 'https://compute.googleapis.com/compute/v1/projects/';

    /** The network tag the firewall rule applies to. */
    private const string TAG = 'buildpusher';

    /**
     * The machine types offered, with vCPUs, memory (MB) and the on-demand price a month in us-central1 (730 hours)
     * plus the 25 GB balanced disk. x86 only, since the server scripts install amd64 packages.
     *
     * @var array<string, array{vcpus: int, memory: int, price: float}>
     */
    private const array TYPES = [
        'e2-small' => ['vcpus' => 2, 'memory' => 2048, 'price' => 14.73],
        'e2-medium' => ['vcpus' => 2, 'memory' => 4096, 'price' => 26.96],
        'e2-standard-2' => ['vcpus' => 2, 'memory' => 8192, 'price' => 51.42],
        'e2-standard-4' => ['vcpus' => 4, 'memory' => 16384, 'price' => 100.33],
        'e2-standard-8' => ['vcpus' => 8, 'memory' => 32768, 'price' => 198.17],
        'e2-highmem-2' => ['vcpus' => 2, 'memory' => 16384, 'price' => 68.49],
        'n2-standard-2' => ['vcpus' => 2, 'memory' => 8192, 'price' => 59.21],
        'n2-standard-4' => ['vcpus' => 4, 'memory' => 16384, 'price' => 115.92],
    ];

    /**
     * The Ubuntu releases offered, by Canonical's image family.
     *
     * @var array<string, array{name: string, family: string}>
     */
    private const array IMAGES = [
        'ubuntu-24.04' => ['name' => '24.04 LTS', 'family' => 'ubuntu-2404-lts-amd64'],
        'ubuntu-22.04' => ['name' => '22.04 LTS', 'family' => 'ubuntu-2204-lts'],
    ];

    /**
     * The service account's key, decoded.
     *
     * @var array{project_id: string, client_email: string, private_key: string, token_uri: string}
     */
    private readonly array $key;

    /**
     * Create a new GoogleCompute instance.
     *
     * @param  string  $token  The service account's JSON key
     */
    public function __construct(string $token)
    {
        $key = json_decode($token, true);
        $key = is_array($key) ? $key : [];
        $this->key = [
            'project_id' => is_string($key['project_id'] ?? null) ? $key['project_id'] : '',
            'client_email' => is_string($key['client_email'] ?? null) ? $key['client_email'] : '',
            'private_key' => is_string($key['private_key'] ?? null) ? $key['private_key'] : '',
            'token_uri' => is_string($key['token_uri'] ?? null) && str_starts_with($key['token_uri'], 'https://oauth2.googleapis.com/') ? $key['token_uri'] : 'https://oauth2.googleapis.com/token',
        ];
    }

    /**
     * Determine whether a credential is a usable service account key.
     *
     * @param  string  $token
     * @return bool
     */
    public static function validKey(string $token): bool
    {
        $key = json_decode($token, true);

        return is_array($key) && ($key['type'] ?? null) === 'service_account' && is_string($key['project_id'] ?? null)
            && preg_match('/\A[a-z][a-z0-9-]{4,28}[a-z0-9]\z/', $key['project_id']) === 1
            && is_string($key['client_email'] ?? null) && str_contains($key['client_email'], '@')
            && is_string($key['private_key'] ?? null) && openssl_pkey_get_private($key['private_key']) !== false;
    }

    /**
     * Get the provider's name.
     *
     * @return string
     */
    public function name(): string
    {
        return 'Google Compute Engine';
    }

    /**
     * Keep the public key to add at launch; it isn't registered with Google. The "fingerprint" carries the key.
     *
     * @param  string  $name
     * @param  string  $publicKey
     * @return CloudSshKeyData
     */
    public function createSshKey(string $name, string $publicKey): CloudSshKeyData
    {
        return new CloudSshKeyData('gce:'.base64_encode(trim($publicKey)), false);
    }

    /**
     * Nothing to remove at Google, since keys aren't registered there.
     *
     * @param  string  $fingerprint
     * @return bool
     */
    public function deleteSshKey(string $fingerprint): bool
    {
        return true;
    }

    /**
     * Create a VM in a zone, making sure the firewall rule exists first.
     *
     * @param  array{name: string, region: string, size: string, image: int|string, ssh_keys?: list<int|string>, user_data?: string|null}  $parameters
     * @return CloudServerData
     */
    public function createServer(array $parameters): CloudServerData
    {
        $zone = $this->zone($parameters['region']);
        $family = self::IMAGES[(string) $parameters['image']]['family'] ?? throw new RuntimeException('Choose Ubuntu 24.04 or 22.04.');
        $this->ensureFirewall();
        $keys = '';
        foreach ($parameters['ssh_keys'] ?? [] as $key) {
            $material = base64_decode(substr((string) $key, strlen('gce:')), true);
            if (is_string($material) && preg_match('/\A[a-z0-9-]+ [A-Za-z0-9+\/=]+( [^\n]*)?\z/', $material) === 1) {
                $keys .= 'echo '.escapeshellarg($material)." >> /root/.ssh/authorized_keys\n";
            }
        }
        $script = (string) ($parameters['user_data'] ?? '');
        $launch = "#!/bin/bash\nmkdir -p /root/.ssh && chmod 700 /root/.ssh\n{$keys}chmod 600 /root/.ssh/authorized_keys\n"
            ."sed -i 's/^#\\?PermitRootLogin .*/PermitRootLogin prohibit-password/' /etc/ssh/sshd_config && (systemctl reload ssh || systemctl reload sshd || true)\n"
            .(str_starts_with($script, '#!') ? substr($script, (int) strpos($script, "\n") + 1) : $script);
        $name = $this->instanceName($parameters['name']);

        $response = $this->client()->post($this->project("/zones/{$zone}/instances"), [
            'name' => $name,
            'machineType' => "zones/{$zone}/machineTypes/{$parameters['size']}",
            'disks' => [['boot' => true, 'autoDelete' => true, 'initializeParams' => [
                'sourceImage' => "projects/ubuntu-os-cloud/global/images/family/{$family}", 'diskSizeGb' => '25', 'diskType' => "zones/{$zone}/diskTypes/pd-balanced",
            ]]],
            'networkInterfaces' => [['network' => 'global/networks/default', 'accessConfigs' => [['type' => 'ONE_TO_ONE_NAT', 'name' => 'External NAT']]]],
            'metadata' => ['items' => [['key' => 'user-data', 'value' => $launch]]],
            'tags' => ['items' => [self::TAG]],
            'labels' => ['managed-by' => 'buildpusher'],
        ]);
        $this->ok($response, 'VM creation');

        return new CloudServerData(
            identifier: "{$zone}/{$name}", name: $name, region: $zone, size: $parameters['size'], image: (string) $parameters['image'],
            publicIp: null, privateIp: null, providerStatus: 'PROVISIONING', readiness: CloudServerData::READINESS_NOT_READY,
        );
    }

    /**
     * Look up a VM.
     *
     * @param  int|string  $identifier  "zone/name"
     * @return CloudServerData
     */
    public function server(int|string $identifier): CloudServerData
    {
        [$zone, $name] = $this->split((string) $identifier);
        $instance = (array) $this->ok($this->client()->get($this->project("/zones/{$zone}/instances/{$name}")), 'VM lookup')->json();
        $interface = is_array($instance['networkInterfaces'][0] ?? null) ? $instance['networkInterfaces'][0] : [];
        $public = is_string($interface['accessConfigs'][0]['natIP'] ?? null) ? $interface['accessConfigs'][0]['natIP'] : null;
        $status = is_string($instance['status'] ?? null) ? $instance['status'] : null;

        return new CloudServerData(
            identifier: "{$zone}/{$name}", name: $name, region: $zone, size: basename((string) ($instance['machineType'] ?? '')), image: '',
            publicIp: $public, privateIp: is_string($interface['networkIP'] ?? null) ? $interface['networkIP'] : null, providerStatus: $status,
            readiness: $status === null ? CloudServerData::READINESS_UNKNOWN
                : ($status === 'RUNNING' && $public !== null ? CloudServerData::READINESS_READY : CloudServerData::READINESS_NOT_READY),
        );
    }

    /**
     * Delete a VM and its disk; a missing one counts as deleted.
     *
     * @param  int|string  $identifier
     * @return bool
     */
    public function deleteServer(int|string $identifier): bool
    {
        [$zone, $name] = $this->split((string) $identifier);
        $response = $this->client()->delete($this->project("/zones/{$zone}/instances/{$name}"));

        return $response->successful() || $response->status() === 404;
    }

    /**
     * List the zones that are up, DigitalOcean-shaped.
     *
     * @return list<array<string, mixed>>
     */
    public function regions(): array
    {
        $zones = [];
        foreach ((array) $this->ok($this->client()->get($this->project('/zones')), 'zone listing')->json('items', []) as $zone) {
            if (is_array($zone) && is_string($zone['name'] ?? null)) {
                $zones[] = ['slug' => $zone['name'], 'name' => $zone['name'], 'available' => ($zone['status'] ?? 'UP') === 'UP'];
            }
        }

        return $zones;
    }

    /**
     * List the machine types offered, DigitalOcean-shaped.
     *
     * @return list<array<string, mixed>>
     */
    public function sizes(): array
    {
        $sizes = [];
        foreach (self::TYPES as $type => $spec) {
            $sizes[] = ['slug' => $type, 'description' => $type, 'memory' => $spec['memory'], 'vcpus' => $spec['vcpus'], 'price_monthly' => $spec['price']];
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
     * Make an authenticated read-only call that checks the key works, for the provider's connection test.
     *
     * @return Response
     */
    public function ping(): Response
    {
        return $this->client()->get($this->project('/zones'), ['maxResults' => 1]);
    }

    /**
     * Snapshot the VM's boot disk (named after the VM); returns the snapshot's name.
     *
     * @param  int|string  $identifier
     * @param  string  $name
     * @return string
     */
    public function snapshotServer(int|string $identifier, string $name): string
    {
        [$zone, $instance] = $this->split((string) $identifier);
        $snapshot = $this->instanceName($instance.'-'.$name.'-'.now()->format('YmdHis'));
        $this->ok($this->client()->post($this->project("/zones/{$zone}/disks/{$instance}/createSnapshot"), ['name' => $snapshot]), 'snapshot');

        return $snapshot;
    }

    /**
     * Delete a disk snapshot.
     *
     * @param  string  $snapshot
     * @return bool
     */
    public function deleteSnapshot(string $snapshot): bool
    {
        $response = $this->client()->delete($this->project('/global/snapshots/'.rawurlencode($snapshot)));

        return $response->successful() || $response->status() === 404;
    }

    /**
     * Create the firewall rule letting SSH, HTTP and HTTPS reach tagged VMs, unless it exists.
     *
     * @return void
     */
    private function ensureFirewall(): void
    {
        $rule = self::TAG.'-web';
        $found = $this->client()->get($this->project('/global/firewalls/'.$rule));
        if ($found->successful()) {
            return;
        }
        if ($found->status() !== 404) {
            $this->ok($found, 'firewall lookup');
        }
        $this->ok($this->client()->post($this->project('/global/firewalls'), [
            'name' => $rule, 'network' => 'global/networks/default', 'direction' => 'INGRESS', 'targetTags' => [self::TAG],
            'sourceRanges' => ['0.0.0.0/0'], 'allowed' => [['IPProtocol' => 'tcp', 'ports' => ['22', '80', '443']]],
            'description' => 'BuildPusher servers: SSH, HTTP and HTTPS',
        ]), 'firewall creation');
    }

    /**
     * Build an authenticated JSON client, with an access token cached for 50 minutes.
     *
     * @return PendingRequest
     */
    private function client(): PendingRequest
    {
        $token = Cache::remember('gce.token.'.sha1($this->key['client_email'].$this->key['private_key']), now()->addMinutes(50), fn (): string => $this->accessToken());

        return Http::withToken($token)->acceptJson()->asJson()->withUserAgent('BuildPusher')->connectTimeout(5)->timeout(20);
    }

    /**
     * Exchange a signed assertion for an access token to the Compute Engine API.
     *
     * @return string
     */
    private function accessToken(): string
    {
        $privateKey = openssl_pkey_get_private($this->key['private_key']);
        if ($privateKey === false || $this->key['client_email'] === '') {
            throw new RuntimeException('That isn’t a Google service account key.');
        }
        $encode = fn (string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
        $now = time();
        $unsigned = $encode((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT'])).'.'.$encode((string) json_encode([
            'iss' => $this->key['client_email'], 'scope' => 'https://www.googleapis.com/auth/compute',
            'aud' => $this->key['token_uri'], 'iat' => $now, 'exp' => $now + 3600,
        ]));
        openssl_sign($unsigned, $signature, $privateKey, OPENSSL_ALGO_SHA256);
        $response = Http::asForm()->connectTimeout(5)->timeout(15)->post($this->key['token_uri'], [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $unsigned.'.'.$encode((string) $signature),
        ]);
        $token = $response->json('access_token');
        if (! $response->successful() || ! is_string($token)) {
            throw new RuntimeException("Google refused the service account key (HTTP {$response->status()}).");
        }

        return $token;
    }

    /**
     * Get a URL under the key's project.
     *
     * @param  string  $path
     * @return string
     */
    private function project(string $path): string
    {
        return self::API.rawurlencode($this->key['project_id']).$path;
    }

    /**
     * Check a zone name's shape before it goes into a URL.
     *
     * @param  string  $zone
     * @return string
     */
    private function zone(string $zone): string
    {
        return preg_match('/\A[a-z]+-[a-z]+\d+-[a-z]\z/', $zone) === 1 ? $zone : throw new RuntimeException('That isn’t a Compute Engine zone.');
    }

    /**
     * Make a valid VM or snapshot name: lowercase letters, digits and hyphens, starting with a letter, at most 63.
     *
     * @param  string  $name
     * @return string
     */
    private function instanceName(string $name): string
    {
        $clean = trim((string) preg_replace('/[^a-z0-9-]+/', '-', strtolower($name)), '-');
        $clean = preg_match('/\A[a-z]/', $clean) === 1 ? $clean : 'bp-'.$clean;

        return rtrim(substr($clean, 0, 63), '-');
    }

    /**
     * Split an ID into its zone and name.
     *
     * @param  string  $identifier
     * @return array{0: string, 1: string}
     */
    private function split(string $identifier): array
    {
        [$zone, $name] = array_pad(explode('/', $identifier, 2), 2, '');

        return [$this->zone($zone), $this->instanceName($name)];
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
            $reason = $response->json('error.errors.0.reason');

            throw new RuntimeException("Compute Engine {$operation} failed with HTTP {$response->status()}".(is_string($reason) ? " ({$reason})" : '').'.');
        }

        return $response;
    }
}
