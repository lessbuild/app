<?php

declare(strict_types=1);

namespace App\Services\Infrastructure;

use App\Contracts\Infrastructure\ServerProvider;
use App\Data\Infrastructure\CloudServerData;
use App\Data\Infrastructure\CloudSshKeyData;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Scaleway Instances. The credential is "PROJECT_ID:SECRET_KEY"; servers are zonal, so their identifier is
 * "zone/id". SSH keys are the project's IAM keys, which Scaleway adds to every new server. Catalogues are returned in
 * the same shape as DigitalOcean's (slug, vcpus, memory in MB, price_monthly) so pricing and right-sizing share code.
 */
class Scaleway implements ServerProvider
{
    /**
     * The API's address.
     *
     * @var string
     */
    private const API = 'https://api.scaleway.com';

    /**
     * The zones servers can be created in, with their names.
     *
     * @var array<string, string>
     */
    public const ZONES = [
        'fr-par-1' => 'Paris 1', 'fr-par-2' => 'Paris 2', 'fr-par-3' => 'Paris 3', 'nl-ams-1' => 'Amsterdam 1',
        'nl-ams-2' => 'Amsterdam 2', 'nl-ams-3' => 'Amsterdam 3', 'pl-waw-1' => 'Warsaw 1', 'pl-waw-2' => 'Warsaw 2', 'pl-waw-3' => 'Warsaw 3',
    ];

    /**
     * The zone whose catalogue the server form shows.
     *
     * @var string
     */
    private const CATALOG_ZONE = 'fr-par-1';

    /**
     * The project servers and keys belong to.
     *
     * @var string
     */
    private readonly string $project;

    /**
     * The API secret key.
     *
     * @var string
     */
    private readonly string $secret;

    /**
     * Create a new Scaleway instance.
     *
     * @param  string  $credential  "PROJECT_ID:SECRET_KEY"
     */
    public function __construct(string $credential)
    {
        [$this->project, $this->secret] = array_pad(explode(':', $credential, 2), 2, '');
    }

    /**
     * Get the provider's name.
     *
     * @return string
     */
    public function name(): string
    {
        return 'Scaleway';
    }

    /**
     * Add the public key to the project's IAM SSH keys, reusing it when it's already there.
     *
     * @param  string  $name
     * @param  string  $publicKey
     * @return CloudSshKeyData
     */
    public function createSshKey(string $name, string $publicKey): CloudSshKeyData
    {
        $response = $this->request()->post(self::API.'/iam/v1alpha1/ssh-keys', ['name' => $name, 'public_key' => trim($publicKey), 'project_id' => $this->project]);
        if ($response->successful() && is_string($response->json('id'))) {
            return new CloudSshKeyData($response->json('id'), true);
        }
        if ($response->status() === 409) {
            $keys = (array) $this->request()->get(self::API.'/iam/v1alpha1/ssh-keys', ['project_id' => $this->project, 'page_size' => 100])->json('ssh_keys', []);
            foreach ($keys as $key) {
                if (is_array($key) && trim((string) ($key['public_key'] ?? '')) === trim($publicKey) && is_string($key['id'] ?? null)) {
                    return new CloudSshKeyData($key['id'], false);
                }
            }
        }

        throw $this->exception($response, 'SSH key creation');
    }

    /**
     * Delete an IAM SSH key.
     *
     * @param  string  $fingerprint  the key's ID
     * @return bool
     */
    public function deleteSshKey(string $fingerprint): bool
    {
        return in_array($this->request()->delete(self::API.'/iam/v1alpha1/ssh-keys/'.rawurlencode($fingerprint))->status(), [204, 404], true);
    }

    /**
     * Create a server with a public IP, hand it the provisioning script through cloud-init, and power it on.
     *
     * @param  array<string, mixed>  $parameters  name, region (zone), size (commercial type), image (image label), user_data
     * @return CloudServerData
     */
    public function createServer(array $parameters): CloudServerData
    {
        $zone = (string) $parameters['region'];
        $base = $this->zone($zone);
        $response = $this->request()->post("{$base}/servers", [
            'name' => $parameters['name'], 'commercial_type' => $parameters['size'], 'project' => $this->project,
            'image' => $this->imageId($zone, (string) $parameters['image'], (string) $parameters['size']), 'dynamic_ip_required' => true,
            'tags' => ['buildpusher'],
        ]);
        if (! $response->successful() || ! is_string($response->json('server.id'))) {
            throw $this->exception($response, 'server creation');
        }
        $id = (string) $response->json('server.id');
        if (filled($parameters['user_data'] ?? null)) {
            $userData = $this->request()->withBody((string) $parameters['user_data'], 'text/plain')->patch("{$base}/servers/{$id}/user_data/cloud-init");
            if (! $userData->successful()) {
                $this->deleteServer("{$zone}/{$id}");
                throw $this->exception($userData, 'cloud-init upload');
            }
        }
        $this->request()->post("{$base}/servers/{$id}/action", ['action' => 'poweron']);

        return $this->serverData($response->json('server'), $zone);
    }

    /**
     * Look up a server by "zone/id".
     *
     * @param  int|string  $identifier
     * @return CloudServerData
     */
    public function server(int|string $identifier): CloudServerData
    {
        [$zone, $id] = $this->split($identifier);
        $response = $this->request()->get($this->zone($zone).'/servers/'.rawurlencode($id));
        if (! $response->successful()) {
            throw $this->exception($response, 'server lookup');
        }

        return $this->serverData($response->json('server'), $zone);
    }

    /**
     * Terminate a server, which also deletes its local volumes and releases its IP.
     *
     * @param  int|string  $identifier
     * @return bool
     */
    public function deleteServer(int|string $identifier): bool
    {
        [$zone, $id] = $this->split($identifier);
        $response = $this->request()->post($this->zone($zone).'/servers/'.rawurlencode($id).'/action', ['action' => 'terminate']);

        return $response->successful() || $response->status() === 404;
    }

    /**
     * List the zones, as DigitalOcean-shaped regions.
     *
     * @return array<array-key, mixed>
     */
    public function regions(): array
    {
        return array_map(fn (string $slug, string $name): array => ['slug' => $slug, 'name' => $name, 'available' => true], array_keys(self::ZONES), self::ZONES);
    }

    /**
     * List the Instance types available in the catalogue zone, as DigitalOcean-shaped sizes.
     *
     * @return array<array-key, mixed>
     */
    public function sizes(): array
    {
        $response = $this->request()->get($this->zone(self::CATALOG_ZONE).'/products/servers', ['per_page' => 100]);
        if (! $response->successful()) {
            throw $this->exception($response, 'Instance type listing');
        }
        $sizes = [];
        foreach ((array) $response->json('servers', []) as $type => $product) {
            if (! is_array($product) || ($product['arch'] ?? 'x86_64') !== 'x86_64') {
                continue;
            }
            $sizes[] = [
                'slug' => (string) $type, 'description' => (string) $type, 'vcpus' => (int) ($product['ncpus'] ?? 0),
                'memory' => (int) round(((float) ($product['ram'] ?? 0)) / 1048576), 'price_monthly' => is_numeric($product['monthly_price'] ?? null) ? (float) $product['monthly_price'] : null,
            ];
        }

        return $sizes;
    }

    /**
     * List the Ubuntu images offered, by marketplace label (the image's ID differs per zone and is looked up on creation).
     *
     * @return array<array-key, mixed>
     */
    public function images(): array
    {
        return [
            ['slug' => 'ubuntu_noble', 'distribution' => 'Ubuntu', 'name' => '24.04 LTS'],
            ['slug' => 'ubuntu_jammy', 'distribution' => 'Ubuntu', 'name' => '22.04 LTS'],
        ];
    }

    /**
     * Find the zone's image for a marketplace label that suits the Instance type.
     *
     * @param  string  $zone
     * @param  string  $label
     * @param  string  $type
     * @return string
     */
    private function imageId(string $zone, string $label, string $type): string
    {
        $response = $this->request()->get(self::API.'/marketplace/v2/local-images', ['zone' => $zone, 'image_label' => $label, 'type' => 'instance_sbs']);
        $images = collect((array) $response->json('local_images', []))->filter(fn ($item): bool => is_array($item));
        $image = $images->first(fn (array $image): bool => in_array($type, (array) ($image['compatible_commercial_types'] ?? []), true)) ?? $images->first();
        if (! is_array($image) || ! is_string($image['id'] ?? null)) {
            throw new RuntimeException("Scaleway has no {$label} image for {$type} in {$zone}.");
        }

        return $image['id'];
    }

    /**
     * Get the Instances API's address for a zone.
     *
     * @param  string  $zone
     * @return string
     */
    private function zone(string $zone): string
    {
        if (! array_key_exists($zone, self::ZONES)) {
            throw new RuntimeException("Scaleway has no zone called {$zone}.");
        }

        return self::API.'/instance/v1/zones/'.$zone;
    }

    /**
     * Split a stored "zone/id" identifier.
     *
     * @param  int|string  $identifier
     * @return array{string, string}
     */
    private function split(int|string $identifier): array
    {
        $parts = explode('/', (string) $identifier, 2);

        return count($parts) === 2 ? [$parts[0], $parts[1]] : [self::CATALOG_ZONE, $parts[0]];
    }

    /**
     * Prepare an authenticated request.
     *
     * @return PendingRequest
     */
    private function request(): PendingRequest
    {
        return Http::acceptJson()->withHeaders(['X-Auth-Token' => $this->secret])->connectTimeout(5)->timeout(15);
    }

    /**
     * Turn Scaleway's server into the shared shape.
     *
     * @param  mixed  $server
     * @param  string  $zone
     * @return CloudServerData
     */
    private function serverData(mixed $server, string $zone): CloudServerData
    {
        if (! is_array($server) || ! isset($server['id'], $server['name'])) {
            throw new RuntimeException('Scaleway returned an incomplete server response.');
        }
        $publicIp = data_get($server, 'public_ip.address') ?? collect((array) ($server['public_ips'] ?? []))->first(fn ($ip): bool => is_array($ip) && ($ip['family'] ?? 'inet') === 'inet')['address'] ?? null;
        $status = is_string($server['state'] ?? null) ? $server['state'] : null;

        return new CloudServerData(
            identifier: $zone.'/'.$server['id'],
            name: (string) $server['name'],
            region: $zone,
            size: (string) ($server['commercial_type'] ?? ''),
            image: (string) data_get($server, 'image.name', ''),
            publicIp: is_string($publicIp) ? $publicIp : null,
            privateIp: is_string($server['private_ip'] ?? null) ? $server['private_ip'] : null,
            providerStatus: $status,
            readiness: $status === null ? CloudServerData::READINESS_UNKNOWN : ($status === 'running' && is_string($publicIp) ? CloudServerData::READINESS_READY : CloudServerData::READINESS_NOT_READY),
        );
    }

    /**
     * Describe a failed call without copying the response body.
     *
     * @param  Response  $response
     * @param  string  $operation
     * @return RuntimeException
     */
    private function exception(Response $response, string $operation): RuntimeException
    {
        return new RuntimeException("Scaleway {$operation} failed with HTTP {$response->status()}.");
    }
}
