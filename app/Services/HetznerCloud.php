<?php

namespace App\Services;

use App\Contracts\ServerProvider;
use App\Data\CloudServerData;
use App\Data\CloudSshKeyData;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class HetznerCloud implements ServerProvider
{
    private const API = 'https://api.hetzner.cloud/v1';

    /**
     * Configure a Hetzner Cloud adapter for one API credential.
     *
     * @param  string  $token  The bearer token used for provider requests.
     */
    public function __construct(private readonly string $token) {}

    /**
     * Identify this cloud provider for displays and diagnostics.
     *
     * @return string The Hetzner Cloud provider label.
     */
    public function name(): string
    {
        return 'Hetzner Cloud';
    }

    /**
     * Register a public key, reusing a matching listed key after duplicate-key responses.
     *
     * @param  string  $name  The provider-visible key name.
     * @param  string  $publicKey  The OpenSSH public key to register.
     * @return CloudSshKeyData The provider key ID and whether it was newly created.
     */
    public function createSshKey(string $name, string $publicKey): CloudSshKeyData
    {
        $response = $this->request()->post(self::API.'/ssh_keys', ['name' => $name, 'public_key' => $publicKey]);
        if ($response->successful() && is_numeric($response->json('ssh_key.id'))) {
            return new CloudSshKeyData((string) $response->json('ssh_key.id'), true);
        }

        if ($response->status() === 409 || $response->status() === 422) {
            $existing = collect($this->request()->get(self::API.'/ssh_keys', ['per_page' => 50])->json('ssh_keys', []))
                ->first(fn (array $key): bool => trim((string) ($key['public_key'] ?? '')) === trim($publicKey));
            if (is_array($existing) && isset($existing['id'])) {
                return new CloudSshKeyData((string) $existing['id'], false);
            }
        }

        throw $this->exception($response, 'SSH key creation');
    }

    /**
     * Delete a Hetzner SSH key by its stored provider reference.
     *
     * @param  string  $fingerprint  The stored key ID used by the shared provider interface.
     * @return bool True for a deleted or already absent key.
     */
    public function deleteSshKey(string $fingerprint): bool
    {
        return in_array($this->request()->delete(self::API.'/ssh_keys/'.rawurlencode($fingerprint))->status(), [204, 404], true);
    }

    /**
     * Translate shared provisioning parameters into a Hetzner server creation request.
     *
     * @param  array<string, mixed>  $parameters  Name, region, size, image and optional SSH keys/user data.
     * @return CloudServerData The normalized server response after successful creation.
     */
    public function createServer(array $parameters): CloudServerData
    {
        $response = $this->request()->post(self::API.'/servers', [
            'name' => $parameters['name'],
            'location' => $parameters['region'],
            'server_type' => $parameters['size'],
            'image' => $parameters['image'],
            'ssh_keys' => $parameters['ssh_keys'] ?? [],
            'user_data' => $parameters['user_data'] ?? null,
            'start_after_create' => true,
        ]);
        if (! $response->successful()) {
            throw $this->exception($response, 'server creation');
        }

        return $this->serverData($response->json('server'));
    }

    /**
     * Fetch and normalize a Hetzner server by its provider ID.
     *
     * @param  int|string  $identifier  The server ID accepted as an integer or string.
     * @return CloudServerData The normalized server identity and available network details.
     */
    public function server(int|string $identifier): CloudServerData
    {
        $response = $this->request()->get(self::API.'/servers/'.rawurlencode((string) $identifier));
        if (! $response->successful()) {
            throw $this->exception($response, 'server lookup');
        }

        return $this->serverData($response->json('server'));
    }

    /**
     * Delete the selected Hetzner server.
     *
     * @param  int|string  $identifier  The provider server ID to delete.
     * @return bool True for a deleted or already absent server.
     */
    public function deleteServer(int|string $identifier): bool
    {
        return in_array($this->request()->delete(self::API.'/servers/'.rawurlencode((string) $identifier))->status(), [204, 404], true);
    }

    /**
     * Fetch Hetzner locations for provisioning choices.
     *
     * @return array<array-key, mixed> The provider location entries.
     */
    public function regions(): array
    {
        $response = $this->request()->get(self::API.'/locations');
        if (! $response->successful()) {
            throw $this->exception($response, 'location listing');
        }

        return $response->json('locations', []);
    }

    /**
     * Fetch Hetzner server types for provisioning choices.
     *
     * @return array<array-key, mixed> The provider server-type entries.
     */
    public function sizes(): array
    {
        $response = $this->request()->get(self::API.'/server_types');
        if (! $response->successful()) {
            throw $this->exception($response, 'server type listing');
        }

        return $response->json('server_types', []);
    }

    /**
     * Fetch Hetzner available system images for provisioning choices.
     *
     * @return array<array-key, mixed> Up to 50 available system image entries.
     */
    public function images(): array
    {
        $response = $this->request()->get(self::API.'/images', [
            'type' => 'system',
            'status' => 'available',
            'per_page' => 50,
        ]);
        if (! $response->successful()) {
            throw $this->exception($response, 'image listing');
        }

        return $response->json('images', []);
    }

    /**
     * Prepare a JSON-accepting bearer-authenticated provider request.
     *
     * @return PendingRequest The pending request with a five-second connection and 15-second total timeout.
     */
    private function request(): PendingRequest
    {
        return Http::acceptJson()->withToken($this->token)->connectTimeout(5)->timeout(15);
    }

    /**
     * Translate a Hetzner response to the shared server representation.
     *
     * @param  mixed  $server  The decoded server object requiring an ID and name.
     * @return CloudServerData The normalized labels and addresses, with empty catalog labels when absent.
     *
     * @throws RuntimeException If the response lacks server identity.
     */
    private function serverData(mixed $server): CloudServerData
    {
        if (! is_array($server) || ! isset($server['id'], $server['name'])) {
            throw new RuntimeException('Hetzner Cloud returned an incomplete server response.');
        }

        return new CloudServerData(
            identifier: (string) $server['id'],
            name: (string) $server['name'],
            region: (string) data_get($server, 'datacenter.location.name', ''),
            size: (string) data_get($server, 'server_type.name', ''),
            image: (string) data_get($server, 'image.name', ''),
            publicIp: data_get($server, 'public_net.ipv4.ip'),
            privateIp: data_get($server, 'private_net.0.ip'),
        );
    }

    /**
     * Describe a failed Hetzner operation without copying provider response bodies.
     *
     * @param  Response  $response  The failed HTTP response.
     * @param  string  $operation  The operation label to include in the diagnostic.
     * @return RuntimeException An exception containing the provider, operation and HTTP status.
     */
    private function exception(Response $response, string $operation): RuntimeException
    {
        return new RuntimeException("Hetzner Cloud {$operation} failed with HTTP {$response->status()}.");
    }
}
