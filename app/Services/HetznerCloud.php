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

    public function __construct(private readonly string $token) {}

    public function name(): string
    {
        return 'Hetzner Cloud';
    }

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

    public function deleteSshKey(string $fingerprint): bool
    {
        return in_array($this->request()->delete(self::API.'/ssh_keys/'.rawurlencode($fingerprint))->status(), [204, 404], true);
    }

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

    public function server(int|string $identifier): CloudServerData
    {
        $response = $this->request()->get(self::API.'/servers/'.rawurlencode((string) $identifier));
        if (! $response->successful()) {
            throw $this->exception($response, 'server lookup');
        }

        return $this->serverData($response->json('server'));
    }

    public function deleteServer(int|string $identifier): bool
    {
        return in_array($this->request()->delete(self::API.'/servers/'.rawurlencode((string) $identifier))->status(), [204, 404], true);
    }

    public function regions(): array
    {
        $response = $this->request()->get(self::API.'/locations');
        if (! $response->successful()) {
            throw $this->exception($response, 'location listing');
        }

        return $response->json('locations', []);
    }

    public function sizes(): array
    {
        $response = $this->request()->get(self::API.'/server_types');
        if (! $response->successful()) {
            throw $this->exception($response, 'server type listing');
        }

        return $response->json('server_types', []);
    }

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

    private function request(): PendingRequest
    {
        return Http::acceptJson()->withToken($this->token)->connectTimeout(5)->timeout(15);
    }

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

    private function exception(Response $response, string $operation): RuntimeException
    {
        return new RuntimeException("Hetzner Cloud {$operation} failed with HTTP {$response->status()}.");
    }
}
