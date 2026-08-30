<?php

namespace App\Services;

use App\Contracts\ServerProvider;
use App\Data\CloudServerData;
use App\Data\CloudSshKeyData;
use Exception;
use GuzzleHttp\Promise\PromiseInterface;
use http\Exception\InvalidArgumentException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class DigitalOcean implements ServerProvider
{
    private string $_API_TOKEN;

    protected static string $_DROPLETS = 'https://api.digitalocean.com/v2/droplets';

    protected static string $_REGIONS = 'https://api.digitalocean.com/v2/regions';

    protected static string $_SIZES = 'https://api.digitalocean.com/v2/sizes';

    protected static string $_IMAGES = 'https://api.digitalocean.com/v2/images';

    protected static string $_KEYS = 'https://api.digitalocean.com/v2/account/keys';

    /**
     * DigitalOcean constructor.
     *
     * @param  string  $api_token  DO API token
     */
    public function __construct(string $api_token)
    {
        $this->_API_TOKEN = $api_token;
    }

    public function name(): string
    {
        return 'DigitalOcean';
    }

    public function regions(): array
    {
        return $this->getRegions();
    }

    public function sizes(): array
    {
        return $this->getSizes();
    }

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

    public function deleteSshKey(string $fingerprint): bool
    {
        $response = $this->delete(self::$_KEYS.'/'.$fingerprint);

        return in_array($response->status(), [204, 404]);
    }

    public function createServer(array $parameters): CloudServerData
    {
        $response = $this->createDroplet($parameters);

        return $this->serverData($response['droplet'] ?? null);
    }

    public function server(int $identifier): CloudServerData
    {
        return $this->serverData($this->getDroplet($identifier));
    }

    public function deleteServer(int $identifier): bool
    {
        return $this->destroyDroplet($identifier);
    }

    /**
     * @return null
     *
     * @throws Exception
     */
    public function getRegions()
    {
        $response = $this->get(self::$_REGIONS, []);

        if ($response->status() != 200) {
            throw new Exception('Invalid response code.');
        }

        return $response->json()['regions'];
    }

    /**
     * @return null
     *
     * @throws Exception
     */
    public function getSizes()
    {
        $response = $this->get(self::$_SIZES.'?per_page=200');

        if ($response->status() != 200) {
            throw new Exception('Invalid response code.');
        }

        return $response->json()['sizes'];
    }

    /**
     * @return null
     *
     * @throws Exception
     */
    public function getImages(string $type = 'distribution')
    {
        $response = $this->get(self::$_IMAGES."?type=$type", []);

        if ($response->status() != 200) {
            throw new Exception('Invalid response code.');
        }

        return $response->json()['images'];
    }

    /**
     * Gets all droplets
     *
     * @return array | null
     *
     * @throws Exception
     */
    public function getDroplets()
    {
        $response = $this->get(self::$_DROPLETS, []);

        if ($response->status() != 200) {
            throw new Exception('Invalid response code.');
        }

        return $response->json()['droplets'];
    }

    /**
     * Gets information about a single droplet
     *
     * @return Json
     *
     * @throws Exception
     */
    public function getDroplet($droplet_id)
    {
        $response = $this->get(self::$_DROPLETS.'/'.$droplet_id, []);

        if (! in_array($response->status(), [200, 202, 204])) {
            throw new Exception('Invalid response code.');
        }

        return $response->json()['droplet'];
    }

    /**
     * Creates a new droplet.
     *
     * @param  array  $params  Droplet parameters. The only mandatory item is 'name'.
     * @return mixed
     *
     * @throws Exception
     */
    public function createDroplet(array $params)
    {
        if (! isset($params['name'])) {
            throw new InvalidArgumentException("Missing the 'name' parameter.");
        }

        $response = $this->post(self::$_DROPLETS, $params);

        if (! in_array($response->status(), [200, 202, 204])) {
            throw $this->apiException($response);
        }

        return $response->json();
    }

    /**
     * Creates a new SSH key or reuses the exact key already in the account.
     *
     * @return array<string, mixed>
     *
     * @throws Exception
     */
    public function createSSH(array $params)
    {
        if (! isset($params['name']) || ! isset($params['public_key'])) {
            throw new Exception("Missing the 'name' or 'key' parameter.");
        }

        $response = $this->post(self::$_KEYS, $params);

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
     * Destroys a Droplet
     *
     *
     * @throws Exception
     */
    public function destroyDroplet($droplet_id): bool
    {
        $response = $this->delete(self::$_DROPLETS.'/'.$droplet_id);

        if (! in_array($response->status(), [200, 202, 204, 404])) {
            return false;
        }

        return true;
    }

    /**
     * Makes a GET query
     *
     * @param  string  $endpoint  API endpoint
     * @param  array  $custom_headers  optional custom headers
     * @return mixed
     */
    public function get(string $endpoint, array $custom_headers = [])
    {
        return Http::withToken($this->_API_TOKEN)
            ->get($endpoint, $custom_headers);
    }

    /**
     * Makes a POST query
     *
     * @return PromiseInterface|Response
     */
    public function post(string $endpoint, array $params)
    {
        return Http::withToken($this->_API_TOKEN)
            ->post($endpoint, $params);
    }

    /**
     * Makes a DELETE query
     *
     * @return PromiseInterface|Response
     */
    public function delete(string $endpoint)
    {
        return Http::withToken($this->_API_TOKEN)
            ->delete($endpoint);
    }

    private function apiException(Response $response): Exception
    {
        $message = $response->json('message');
        $detail = is_string($message) && $message !== ''
            ? ': '.str($message)->squish()->limit(500)
            : '';

        return new Exception("DigitalOcean request failed with HTTP {$response->status()}{$detail}");
    }

    /**
     * @return array<string, mixed>|null
     */
    private function existingSshKey(string $publicKey): ?array
    {
        $blob = $this->publicKeyBlob($publicKey);
        if ($blob === null) {
            return null;
        }

        $fingerprint = implode(':', str_split(md5($blob), 2));
        $response = $this->get(self::$_KEYS.'/'.$fingerprint);
        $sshKey = $response->status() === 200 ? $response->json('ssh_key') : null;
        if (! is_array($sshKey) || ! is_string($sshKey['public_key'] ?? null)) {
            return null;
        }

        $existingBlob = $this->publicKeyBlob($sshKey['public_key']);

        return $existingBlob !== null && hash_equals($blob, $existingBlob)
            ? array_merge($sshKey, ['fingerprint' => $fingerprint])
            : null;
    }

    private function publicKeyBlob(string $publicKey): ?string
    {
        $parts = preg_split('/\s+/', trim($publicKey));
        if (! isset($parts[1])) {
            return null;
        }

        $decoded = base64_decode($parts[1], true);

        return $decoded === false || $decoded === '' ? null : $decoded;
    }

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

        $networks = collect($droplet['networks']['v4'] ?? []);

        return new CloudServerData(
            identifier: (int) $droplet['id'],
            name: (string) $droplet['name'],
            region: (string) $droplet['region']['name'],
            size: (string) $droplet['size']['slug'],
            image: (string) $droplet['image']['name'],
            publicIp: $networks->firstWhere('type', 'public')['ip_address'] ?? null,
            privateIp: $networks->firstWhere('type', 'private')['ip_address'] ?? null,
        );
    }
}
