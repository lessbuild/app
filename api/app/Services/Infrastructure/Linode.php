<?php

declare(strict_types=1);

namespace App\Services\Infrastructure;

use App\Contracts\Infrastructure\ServerProvider;
use App\Contracts\Infrastructure\SnapshotsServers;
use App\Data\Infrastructure\CloudServerData;
use App\Data\Infrastructure\CloudSshKeyData;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Linode (Akamai Cloud) servers over its v4 API with a personal access token. Instances get the SSH key through
 * `authorized_keys` and the provisioning script through Metadata user data.
 */
class Linode implements ServerProvider, SnapshotsServers
{
    private const API = 'https://api.linode.com/v4';

    /**
     * Create a new Linode instance.
     *
     * @param  string  $token  A personal access token with Linodes and Account read/write.
     */
    public function __construct(private readonly string $token) {}

    /**
     * Get the provider's name.
     *
     * @return string
     */
    public function name(): string
    {
        return 'Linode';
    }

    /**
     * Register the public key on the profile, or find it when it's already there.
     *
     * @param  string  $name
     * @param  string  $publicKey
     * @return CloudSshKeyData
     */
    public function createSshKey(string $name, string $publicKey): CloudSshKeyData
    {
        $keys = (array) $this->request()->get(self::API.'/profile/sshkeys', ['page_size' => 500])->json('data', []);
        $existing = collect($keys)->first(fn (mixed $key): bool => is_array($key) && trim((string) ($key['ssh_key'] ?? '')) === trim($publicKey));
        if (is_array($existing) && isset($existing['id'])) {
            return new CloudSshKeyData((string) $existing['id'], false);
        }
        $response = $this->request()->post(self::API.'/profile/sshkeys', ['label' => Str::limit($name, 64, ''), 'ssh_key' => trim($publicKey)]);
        if ($response->successful() && filled($response->json('id'))) {
            return new CloudSshKeyData((string) $response->json('id'), true);
        }

        throw $this->exception($response, 'SSH key creation');
    }

    /**
     * Remove a key from the profile; a missing key counts as removed.
     *
     * @param  string  $fingerprint  the key's ID
     * @return bool
     */
    public function deleteSshKey(string $fingerprint): bool
    {
        return in_array($this->request()->delete(self::API.'/profile/sshkeys/'.rawurlencode($fingerprint))->status(), [200, 404], true);
    }

    /**
     * Create an instance from a size (type), region and image, authorised for the given keys (Linode takes the key
     * material, so each ID is looked up) and with a random root password nobody keeps, since access is by key.
     *
     * @param  array{name: string, region: string, size: string, image: int|string, ssh_keys?: list<int|string>, user_data?: string|null}  $parameters
     * @return CloudServerData
     */
    public function createServer(array $parameters): CloudServerData
    {
        $keys = [];
        foreach ($parameters['ssh_keys'] ?? [] as $id) {
            $key = $this->request()->get(self::API.'/profile/sshkeys/'.rawurlencode((string) $id));
            if ($key->successful() && is_string($key->json('ssh_key'))) {
                $keys[] = $key->json('ssh_key');
            }
        }
        $response = $this->request()->post(self::API.'/linode/instances', [
            'label' => $this->label($parameters['name']),
            'region' => $parameters['region'],
            'type' => $parameters['size'],
            'image' => (string) $parameters['image'],
            'root_pass' => Str::password(40),
            'authorized_keys' => $keys,
            'booted' => true,
            'metadata' => ['user_data' => base64_encode((string) ($parameters['user_data'] ?? ''))],
        ]);
        if (! $response->successful()) {
            throw $this->exception($response, 'instance creation');
        }

        return $this->serverData($response->json());
    }

    /**
     * Look up an instance.
     *
     * @param  int|string  $identifier
     * @return CloudServerData
     */
    public function server(int|string $identifier): CloudServerData
    {
        $response = $this->request()->get(self::API.'/linode/instances/'.rawurlencode((string) $identifier));
        if (! $response->successful()) {
            throw $this->exception($response, 'instance lookup');
        }

        return $this->serverData($response->json());
    }

    /**
     * Delete an instance; a missing one counts as deleted.
     *
     * @param  int|string  $identifier
     * @return bool
     */
    public function deleteServer(int|string $identifier): bool
    {
        return in_array($this->request()->delete(self::API.'/linode/instances/'.rawurlencode((string) $identifier))->status(), [200, 404], true);
    }

    /**
     * List the regions that offer Linodes and Metadata (needed for the provisioning script).
     *
     * @return list<array<string, mixed>>
     */
    public function regions(): array
    {
        $response = $this->request()->get(self::API.'/regions', ['page_size' => 500]);
        if (! $response->successful()) {
            throw $this->exception($response, 'region listing');
        }

        return array_values(array_filter((array) $response->json('data', []), fn (mixed $region): bool => is_array($region)
            && in_array('Linodes', (array) ($region['capabilities'] ?? []), true) && in_array('Metadata', (array) ($region['capabilities'] ?? []), true)
            && ($region['status'] ?? 'ok') === 'ok'));
    }

    /**
     * List the shared and dedicated CPU instance types.
     *
     * @return list<array<string, mixed>>
     */
    public function sizes(): array
    {
        $response = $this->request()->get(self::API.'/linode/types', ['page_size' => 500]);
        if (! $response->successful()) {
            throw $this->exception($response, 'type listing');
        }

        return array_values(array_filter((array) $response->json('data', []), fn (mixed $type): bool => is_array($type) && in_array($type['class'] ?? '', ['nanode', 'standard', 'dedicated'], true)));
    }

    /**
     * List the public images that support Metadata (cloud-init).
     *
     * @return list<array<string, mixed>>
     */
    public function images(): array
    {
        $response = $this->request()->get(self::API.'/images', ['page_size' => 500]);
        if (! $response->successful()) {
            throw $this->exception($response, 'image listing');
        }

        return array_values(array_filter((array) $response->json('data', []), fn (mixed $image): bool => is_array($image) && ($image['is_public'] ?? false)
            && in_array('cloud-init', (array) ($image['capabilities'] ?? []), true)));
    }

    /**
     * Make a label Linode accepts: 3–64 letters, digits, dashes, underscores or dots, starting and ending with a
     * letter or digit.
     *
     * @param  string  $name
     * @return string
     */
    private function label(string $name): string
    {
        $label = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $name), '-_.');
        $label = substr($label, 0, 64);

        return strlen($label) >= 3 ? $label : 'server-'.$label.Str::lower(Str::random(4));
    }

    /**
     * Build the request with the token.
     *
     * @return PendingRequest
     */
    private function request(): PendingRequest
    {
        return Http::acceptJson()->withToken($this->token)->withHeaders(['User-Agent' => 'BuildPusher'])->connectTimeout(5)->timeout(15);
    }

    /**
     * Normalise an instance into the shared shape: ready once it's running with a public IPv4 address.
     *
     * @param  mixed  $server
     * @return CloudServerData
     */
    private function serverData(mixed $server): CloudServerData
    {
        if (! is_array($server) || ! isset($server['id'])) {
            throw new RuntimeException('Linode returned no instance.');
        }
        $addresses = array_values(array_filter((array) ($server['ipv4'] ?? []), 'is_string'));
        $public = collect($addresses)->first(fn (string $ip): bool => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE) !== false);
        $private = collect($addresses)->first(fn (string $ip): bool => $ip !== $public);
        $status = is_string($server['status'] ?? null) ? $server['status'] : null;

        return new CloudServerData(
            identifier: (string) $server['id'],
            name: (string) ($server['label'] ?? ''),
            region: (string) ($server['region'] ?? ''),
            size: (string) ($server['type'] ?? ''),
            image: (string) ($server['image'] ?? ''),
            publicIp: is_string($public) ? $public : null,
            privateIp: is_string($private) ? $private : null,
            providerStatus: $status,
            readiness: $status === null ? CloudServerData::READINESS_UNKNOWN
                : ($status === 'running' && is_string($public) ? CloudServerData::READINESS_READY : CloudServerData::READINESS_NOT_READY),
        );
    }

    /**
     * Describe a failed Linode operation without exposing its response.
     *
     * @param  Response  $response
     * @param  string  $operation
     * @return RuntimeException
     */
    private function exception(Response $response, string $operation): RuntimeException
    {
        return new RuntimeException("Linode {$operation} failed with HTTP {$response->status()}.");
    }

    /**
     * Take a manual backup snapshot of the Linode (it needs the Backup service on the Linode); returns its ID, with the
     * Linode's, as "linode/backup".
     *
     * @param  int|string  $identifier
     * @param  string  $name
     * @return string
     */
    public function snapshotServer(int|string $identifier, string $name): string
    {
        $response = $this->request()->post(self::API.'/linode/instances/'.rawurlencode((string) $identifier).'/backups', ['label' => mb_substr($name, 0, 255)]);
        if (! $response->successful() || ! is_scalar($response->json('id'))) {
            throw $this->exception($response, 'snapshot');
        }

        return $identifier.'/'.$response->json('id');
    }

    /**
     * Linode keeps one manual snapshot per Linode and replaces it with the next, so there's nothing to delete.
     *
     * @param  string  $snapshot
     * @return bool
     */
    public function deleteSnapshot(string $snapshot): bool
    {
        return true;
    }
}
