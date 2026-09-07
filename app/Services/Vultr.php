<?php

namespace App\Services;

use App\Contracts\ServerProvider;
use App\Data\CloudServerData;
use App\Data\CloudSshKeyData;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class Vultr implements ServerProvider
{
    private const API = 'https://api.vultr.com/v2';

    /**
     * Configure the Vultr client with the account API credential.
     *
     * @param  string  $token  Bearer token used for authenticated provider requests.
     */
    public function __construct(private readonly string $token) {}

    /**
     * Identify the cloud platform in messages and provider selections.
     *
     * @return string Human-readable provider name.
     */
    public function name(): string
    {
        return 'Vultr';
    }

    /**
     * Register the public key or reuse an exact existing match in the provider account.
     *
     * @param  string  $name  Label for a newly created provider key.
     * @param  string  $publicKey  Public SSH key material to register.
     * @return CloudSshKeyData Provider key reference and whether this call created it.
     */
    public function createSshKey(string $name, string $publicKey): CloudSshKeyData
    {
        $response = $this->request()->post(self::API.'/ssh-keys', ['name' => $name, 'ssh_key' => $publicKey]);
        if ($response->successful() && filled($response->json('ssh_key.id'))) {
            return new CloudSshKeyData((string) $response->json('ssh_key.id'), true);
        }

        if (in_array($response->status(), [400, 409], true)) {
            $existing = collect($this->request()->get(self::API.'/ssh-keys', ['per_page' => 100])->json('ssh_keys', []))
                ->first(fn (array $key): bool => trim((string) ($key['ssh_key'] ?? '')) === trim($publicKey));
            if (is_array($existing) && isset($existing['id'])) {
                return new CloudSshKeyData((string) $existing['id'], false);
            }
        }

        throw $this->exception($response, 'SSH key creation');
    }

    /**
     * Remove a provider SSH key, accepting an already absent key as success.
     *
     * @param  string  $fingerprint  Provider fingerprint or opaque key ID returned during registration.
     * @return bool Whether the provider accepted deletion or reported the key absent.
     */
    public function deleteSshKey(string $fingerprint): bool
    {
        return in_array($this->request()->delete(self::API.'/ssh-keys/'.rawurlencode($fingerprint))->status(), [204, 404], true);
    }

    /**
     * Provision a cloud instance from the shared server parameters.
     *
     * @param  array{name: string, region: string, size: string, image: int|string, ssh_keys?: list<int|string>, user_data?: string|null, ...}  $parameters  Provider identifiers and optional bootstrap settings; additional provider fields may be supplied.
     * @return CloudServerData Normalized instance metadata; addresses may still be unavailable.
     */
    public function createServer(array $parameters): CloudServerData
    {
        $response = $this->request()->post(self::API.'/instances', [
            'region' => $parameters['region'],
            'plan' => $parameters['size'],
            'os_id' => (int) $parameters['image'],
            'label' => $parameters['name'],
            'hostname' => $parameters['name'],
            'sshkey_id' => $parameters['ssh_keys'] ?? [],
            'user_data' => base64_encode((string) ($parameters['user_data'] ?? '')),
            'activation_email' => false,
        ]);
        if (! $response->successful()) {
            throw $this->exception($response, 'instance creation');
        }

        return $this->serverData($response->json('instance'));
    }

    /**
     * Fetch current instance metadata from the cloud account.
     *
     * @param  int|string  $identifier  Native instance ID returned by the provider.
     * @return CloudServerData Normalized instance metadata and any assigned IP addresses.
     */
    public function server(int|string $identifier): CloudServerData
    {
        $response = $this->request()->get(self::API.'/instances/'.rawurlencode((string) $identifier));
        if (! $response->successful()) {
            throw $this->exception($response, 'instance lookup');
        }

        return $this->serverData($response->json('instance'));
    }

    /**
     * Delete an instance, accepting an already absent instance as success.
     *
     * @param  int|string  $identifier  Native instance ID returned by the provider.
     * @return bool Whether the provider accepted deletion or reported the instance absent.
     */
    public function deleteServer(int|string $identifier): bool
    {
        return in_array($this->request()->delete(self::API.'/instances/'.rawurlencode((string) $identifier))->status(), [204, 404], true);
    }

    /**
     * Fetch the regions returned by the Vultr catalog endpoint.
     *
     * @return list<array<string, mixed>> Provider-specific catalog records; field names are not normalized.
     */
    public function regions(): array
    {
        $response = $this->request()->get(self::API.'/regions');
        if (! $response->successful()) {
            throw $this->exception($response, 'region listing');
        }

        return $response->json('regions', []);
    }

    /**
     * Fetch up to 500 regular-compute plans from the Vultr catalog.
     *
     * @return list<array<string, mixed>> Provider-specific catalog records; field names are not normalized.
     */
    public function sizes(): array
    {
        $response = $this->request()->get(self::API.'/plans', ['type' => 'vc2', 'per_page' => 500]);
        if (! $response->successful()) {
            throw $this->exception($response, 'plan listing');
        }

        return $response->json('plans', []);
    }

    /**
     * Fetch up to 500 operating-system images from the Vultr catalog.
     *
     * @return list<array<string, mixed>> Provider-specific catalog records; field names are not normalized.
     */
    public function images(): array
    {
        $response = $this->request()->get(self::API.'/os', ['per_page' => 500]);
        if (! $response->successful()) {
            throw $this->exception($response, 'operating system listing');
        }

        return $response->json('os', []);
    }

    /**
     * Prepare the authenticated JSON client used for Vultr API operations.
     *
     * @return PendingRequest Client with the application user agent, a five-second connection timeout, and a fifteen-second request timeout.
     */
    private function request(): PendingRequest
    {
        return Http::acceptJson()->withToken($this->token)->withHeaders(['User-Agent' => 'BuildPusher'])
            ->connectTimeout(5)->timeout(15);
    }

    /**
     * Normalize a Vultr instance response after requiring its provider identifier.
     *
     * @param  mixed  $server  Decoded instance response; non-arrays and missing IDs are rejected.
     * @return CloudServerData Common instance fields with nullable addresses and empty defaults for absent metadata.
     *
     * @throws RuntimeException When the response does not identify an instance.
     */
    private function serverData(mixed $server): CloudServerData
    {
        if (! is_array($server) || ! isset($server['id'])) {
            throw new RuntimeException('Vultr returned an incomplete instance response.');
        }

        return new CloudServerData(
            identifier: (string) $server['id'],
            name: (string) ($server['hostname'] ?? $server['label'] ?? ''),
            region: (string) ($server['region'] ?? ''),
            size: (string) ($server['plan'] ?? ''),
            image: (string) ($server['os_id'] ?? $server['os'] ?? ''),
            publicIp: $server['main_ip'] ?? null,
            privateIp: $server['internal_ip'] ?? null,
        );
    }

    /**
     * Describe a failed Vultr operation without exposing provider response contents.
     *
     * @param  Response  $response  HTTP response whose status is included in the message.
     * @param  string  $operation  Operation label such as server creation or region listing.
     * @return RuntimeException Exception for the caller to throw; this method does not throw it itself.
     */
    private function exception(Response $response, string $operation): RuntimeException
    {
        return new RuntimeException("Vultr {$operation} failed with HTTP {$response->status()}.");
    }
}
