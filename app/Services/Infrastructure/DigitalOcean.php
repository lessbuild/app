<?php

declare(strict_types=1);

namespace App\Services\Infrastructure;

use App\Contracts\Infrastructure\ServerProvider;
use App\Data\Infrastructure\CloudServerData;
use App\Data\Infrastructure\CloudSshKeyData;
use Exception;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

class DigitalOcean implements ServerProvider
{
    private const DROPLETS = 'https://api.digitalocean.com/v2/droplets';

    private const REGIONS = 'https://api.digitalocean.com/v2/regions';

    private const SIZES = 'https://api.digitalocean.com/v2/sizes';

    private const IMAGES = 'https://api.digitalocean.com/v2/images';

    private const KEYS = 'https://api.digitalocean.com/v2/account/keys';

    /**
     * Create a new DigitalOcean instance.
     *
     * A client for one DigitalOcean account.
     *
     * @param  string  $apiToken  The account's personal access token, sent as a bearer token on every request.
     */
    public function __construct(private readonly string $apiToken) {}

    /**
     * Identify this cloud provider for displays and diagnostics.
     *
     * @return string The DigitalOcean provider label.
     */
    public function name(): string
    {
        return 'DigitalOcean';
    }

    /**
     * Read the available DigitalOcean region catalog.
     *
     * @return array<array-key, mixed> The provider's region entries from the corresponding catalog request.
     */
    public function regions(): array
    {
        return $this->getRegions();
    }

    /**
     * Read the available DigitalOcean machine size catalog.
     *
     * @return array<array-key, mixed> The provider's machine size entries from the corresponding catalog request.
     */
    public function sizes(): array
    {
        return $this->getSizes();
    }

    /**
     * Read the available DigitalOcean image catalog.
     *
     * @return array<array-key, mixed> The provider's image entries from the corresponding catalog request.
     */
    public function images(): array
    {
        return $this->getImages();
    }

    /**
     * Register or reuse the supplied public SSH key and normalize its fingerprint.
     *
     * @param  string  $name  The provider-visible key label.
     * @param  string  $publicKey  The OpenSSH public key to register.
     * @return CloudSshKeyData The key fingerprint and whether the provider key was newly created.
     *
     * @throws Exception If the provider returns no usable fingerprint.
     */
    public function createSshKey(string $name, string $publicKey): CloudSshKeyData
    {
        $sshKey = $this->createSSH([
            'name' => $name,
            'public_key' => $publicKey,
        ]);
        $fingerprint = $sshKey['fingerprint'] ?? null;

        if (! is_string($fingerprint) || $fingerprint === '') {
            throw new Exception('DigitalOcean returned an incomplete SSH key response.');
        }

        return new CloudSshKeyData(
            fingerprint: $fingerprint,
            created: (bool) ($sshKey['created'] ?? true),
        );
    }

    /**
     * Remove a DigitalOcean SSH key by fingerprint.
     *
     * @param  string  $fingerprint  The provider key fingerprint.
     * @return bool True when deletion succeeds or the key is already absent.
     */
    public function deleteSshKey(string $fingerprint): bool
    {
        $response = $this->delete(self::KEYS.'/'.$fingerprint);

        return in_array($response->status(), [204, 404]);
    }

    /**
     * Create a droplet and normalize the returned server identity.
     *
     * @param  array<string, mixed>  $parameters  The provider-specific droplet creation payload.
     * @return CloudServerData The normalized droplet details, including available network addresses.
     */
    public function createServer(array $parameters): CloudServerData
    {
        $response = $this->createDroplet($parameters);

        return $this->serverData($response['droplet'] ?? null);
    }

    /**
     * Fetch a droplet and normalize its current state.
     *
     * @param  int|string  $identifier  The droplet's numeric identifier, accepting a numeric string.
     * @return CloudServerData The normalized server details.
     */
    public function server(int|string $identifier): CloudServerData
    {
        return $this->serverData($this->getDroplet((int) $identifier));
    }

    /**
     * Destroy a droplet by its provider identifier.
     *
     * @param  int|string  $identifier  The droplet's numeric identifier, accepting a numeric string.
     * @return bool Whether the provider deletion helper reports success.
     */
    public function deleteServer(int|string $identifier): bool
    {
        return $this->destroyDroplet((int) $identifier);
    }

    /**
     * Get DigitalOcean's regions.
     *
     * @return array<mixed> Available regions returned by the provider.
     *
     * @throws Exception
     */
    public function getRegions(): array
    {
        $response = $this->get(self::REGIONS, []);

        if ($response->status() !== 200) {
            throw new Exception('Invalid response code.');
        }

        return $this->jsonArray($response, 'regions');
    }

    /**
     * Get DigitalOcean's droplet sizes (up to 200).
     *
     * @return array<mixed> Available instance sizes returned by the provider.
     *
     * @throws Exception
     */
    public function getSizes(): array
    {
        $response = $this->get(self::SIZES.'?per_page=200');

        if ($response->status() !== 200) {
            throw new Exception('Invalid response code.');
        }

        return $this->jsonArray($response, 'sizes');
    }

    /**
     * Get DigitalOcean's images of a type, distributions by default.
     *
     * @param  string  $type  Provider image category, defaulting to distribution images.
     * @return array<mixed> Images in the requested category.
     *
     * @throws Exception
     */
    public function getImages(string $type = 'distribution'): array
    {
        $response = $this->get(self::IMAGES."?type=$type", []);

        if ($response->status() !== 200) {
            throw new Exception('Invalid response code.');
        }

        return $this->jsonArray($response, 'images');
    }

    /**
     * Get all droplets
     *
     * @return array<mixed> Droplets in the provider response.
     *
     * @throws Exception
     */
    public function getDroplets(): array
    {
        $response = $this->get(self::DROPLETS, []);

        if ($response->status() !== 200) {
            throw new Exception('Invalid response code.');
        }

        return $this->jsonArray($response, 'droplets');
    }

    /**
     * Get information about a single droplet
     *
     * @param  int|string  $droplet_id  The provider's droplet identifier.
     * @return array<string, mixed> Decoded droplet metadata.
     *
     * @throws Exception
     */
    public function getDroplet(int|string $droplet_id): array
    {
        $response = $this->get(self::DROPLETS.'/'.$droplet_id, []);

        if (! in_array($response->status(), [200, 202, 204])) {
            throw new Exception('Invalid response code.');
        }

        return $this->jsonArray($response, 'droplet');
    }

    /**
     * Create a new droplet.
     *
     * @param  array<string, mixed>  $params  Droplet parameters. The only locally mandatory item is 'name'.
     * @return array<string, mixed> The decoded creation response.
     *
     * @throws Exception
     */
    public function createDroplet(array $params): array
    {
        if (! isset($params['name'])) {
            throw new InvalidArgumentException("Missing the 'name' parameter.");
        }

        $response = $this->post(self::DROPLETS, $params);

        if (! in_array($response->status(), [200, 202, 204])) {
            throw $this->apiException($response);
        }

        return $this->jsonArray($response);
    }

    /**
     * Create a new SSH key or reuses the exact key already in the account.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     *
     * @throws Exception
     */
    public function createSSH(array $params): array
    {
        if (! isset($params['name']) || ! isset($params['public_key'])) {
            throw new Exception("Missing the 'name' or 'key' parameter.");
        }

        $response = $this->post(self::KEYS, $params);

        if ($response->status() === 422) {
            $existing = $this->existingSshKey($params['public_key']);
            if ($existing) {
                return array_merge($existing, ['created' => false]);
            }
        }

        if ($response->status() !== 201) {
            throw $this->apiException($response);
        }

        $sshKey = $response->json('ssh_key');
        if (! is_array($sshKey)) {
            throw new Exception('DigitalOcean returned an incomplete SSH key response.');
        }

        return array_merge($sshKey, ['created' => true]);
    }

    /**
     * Destroy a Droplet
     *
     * @param  string|int  $droplet_id
     * @return bool
     *
     * @throws Exception
     */
    public function destroyDroplet(int|string $droplet_id): bool
    {
        $response = $this->delete(self::DROPLETS.'/'.$droplet_id);

        if (! in_array($response->status(), [200, 202, 204, 404])) {
            return false;
        }

        return true;
    }

    /**
     * Make a GET query
     *
     * @param  string  $endpoint  API endpoint
     * @param  array<string, mixed>  $custom_headers  Optional query parameters (legacy argument name retained).
     * @return Response The synchronous HTTP response.
     */
    public function get(string $endpoint, array $custom_headers = []): Response
    {
        return Http::withToken($this->apiToken)
            ->get($endpoint, $custom_headers);
    }

    /**
     * Make a POST query
     *
     * @param  string  $endpoint  API endpoint.
     * @param  array<string, mixed>  $params  JSON request attributes.
     * @return Response The synchronous HTTP response.
     */
    public function post(string $endpoint, array $params): Response
    {
        return Http::withToken($this->apiToken)
            ->post($endpoint, $params);
    }

    /**
     * Make a DELETE query
     *
     * @param  string  $endpoint  API endpoint.
     * @return Response The synchronous HTTP response.
     */
    public function delete(string $endpoint): Response
    {
        return Http::withToken($this->apiToken)
            ->delete($endpoint);
    }

    /**
     * Read a JSON array from a response, or throw when DigitalOcean returned something incomplete.
     *
     * @param  Response  $response  The provider response to decode.
     * @param  string|null  $key  The top-level payload key, or null for the complete response.
     * @return array<array-key, mixed> The decoded object or list.
     *
     * @throws Exception When a successful response contains an incomplete or malformed payload.
     */
    private function jsonArray(Response $response, ?string $key = null): array
    {
        $data = $response->json($key);
        if (! is_array($data)) {
            throw new Exception('DigitalOcean returned an incomplete API response.');
        }

        return $data;
    }

    /**
     * Build a bounded diagnostic exception from a failed provider response.
     *
     * @param  Response  $response  The unsuccessful DigitalOcean HTTP response.
     * @return Exception An exception containing the HTTP status and optional bounded provider message.
     */
    private function apiException(Response $response): Exception
    {
        $message = $response->json('message');
        $detail = is_string($message) && $message !== ''
            ? ': '.str($message)->squish()->limit(500)
            : '';

        return new Exception("DigitalOcean request failed with HTTP {$response->status()}{$detail}");
    }

    /**
     * Find the account's SSH key with the same key material by its MD5 fingerprint, so importing the same key twice
     * reuses it. Null when there isn't one.
     *
     * @param  string  $publicKey
     * @return array<string, mixed>|null
     */
    private function existingSshKey(string $publicKey): ?array
    {
        $blob = $this->publicKeyBlob($publicKey);
        if ($blob === null) {
            return null;
        }

        $fingerprint = implode(':', str_split(md5($blob), 2));
        $response = $this->get(self::KEYS.'/'.$fingerprint);
        $sshKey = $response->status() === 200 ? $response->json('ssh_key') : null;
        if (! is_array($sshKey) || ! is_string($sshKey['public_key'] ?? null)) {
            return null;
        }

        $existingBlob = $this->publicKeyBlob($sshKey['public_key']);

        return $existingBlob !== null && hash_equals($blob, $existingBlob)
            ? array_merge($sshKey, ['fingerprint' => $fingerprint])
            : null;
    }

    /**
     * Decode the binary key blob from an OpenSSH public key.
     *
     * @param  string  $publicKey  The key algorithm, base64 payload and optional comment.
     * @return string|null The decoded nonempty key bytes, or null for malformed input.
     */
    private function publicKeyBlob(string $publicKey): ?string
    {
        $parts = preg_split('/\s+/', trim($publicKey));
        if (! isset($parts[1])) {
            return null;
        }

        $decoded = base64_decode($parts[1], true);

        return $decoded === false || $decoded === '' ? null : $decoded;
    }

    /**
     * Validate a droplet response and translate it to the shared server representation.
     *
     * @param  mixed  $droplet  The decoded provider droplet object.
     * @return CloudServerData The normalized identity, catalog labels and IPv4 addresses.
     *
     * @throws Exception If required server fields are missing.
     */
    private function serverData(mixed $droplet): CloudServerData
    {
        if (! is_array($droplet)
            || ! isset(
                $droplet['id'],
                $droplet['name'],
                $droplet['region']['name'],
                $droplet['size']['slug'],
                $droplet['image']['name'],
            )) {
            throw new Exception('DigitalOcean returned an incomplete server response.');
        }

        /** @var list<array<string, mixed>> $v4 */
        $v4 = is_array($droplet['networks']['v4'] ?? null) ? $droplet['networks']['v4'] : [];
        $networks = collect($v4);
        $publicIp = $networks->firstWhere('type', 'public')['ip_address'] ?? null;
        $providerStatus = is_scalar($droplet['status'] ?? null) ? (string) $droplet['status'] : null;

        return new CloudServerData(
            identifier: (int) $droplet['id'],
            name: (string) $droplet['name'],
            region: (string) $droplet['region']['name'],
            size: (string) $droplet['size']['slug'],
            image: (string) $droplet['image']['name'],
            publicIp: $publicIp,
            privateIp: $networks->firstWhere('type', 'private')['ip_address'] ?? null,
            providerStatus: $providerStatus,
            readiness: $this->readiness($providerStatus, $publicIp, 'active'),
        );
    }

    /**
     * Normalize DigitalOcean's active state into the shared provider readiness contract.
     *
     * @param  string|null  $status  Provider droplet status.
     * @param  string|null  $publicIp  Public address reported by the provider.
     * @param  string  $readyStatus  Provider status that means the instance is running.
     * @return 'ready'|'not_ready'|'unknown'
     */
    private function readiness(?string $status, ?string $publicIp, string $readyStatus): string
    {
        if (blank($status)) {
            return CloudServerData::READINESS_UNKNOWN;
        }

        return $status === $readyStatus && filled($publicIp)
            ? CloudServerData::READINESS_READY
            : CloudServerData::READINESS_NOT_READY;
    }
}
