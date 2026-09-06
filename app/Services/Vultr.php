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

    public function __construct(private readonly string $token) {}

    public function name(): string
    {
        return 'Vultr';
    }

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

    public function deleteSshKey(string $fingerprint): bool
    {
        return in_array($this->request()->delete(self::API.'/ssh-keys/'.rawurlencode($fingerprint))->status(), [204, 404], true);
    }

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

    public function server(int|string $identifier): CloudServerData
    {
        $response = $this->request()->get(self::API.'/instances/'.rawurlencode((string) $identifier));
        if (! $response->successful()) {
            throw $this->exception($response, 'instance lookup');
        }

        return $this->serverData($response->json('instance'));
    }

    public function deleteServer(int|string $identifier): bool
    {
        return in_array($this->request()->delete(self::API.'/instances/'.rawurlencode((string) $identifier))->status(), [204, 404], true);
    }

    public function regions(): array
    {
        $response = $this->request()->get(self::API.'/regions');
        if (! $response->successful()) {
            throw $this->exception($response, 'region listing');
        }

        return $response->json('regions', []);
    }

    public function sizes(): array
    {
        $response = $this->request()->get(self::API.'/plans', ['type' => 'vc2', 'per_page' => 500]);
        if (! $response->successful()) {
            throw $this->exception($response, 'plan listing');
        }

        return $response->json('plans', []);
    }

    public function images(): array
    {
        $response = $this->request()->get(self::API.'/os', ['per_page' => 500]);
        if (! $response->successful()) {
            throw $this->exception($response, 'operating system listing');
        }

        return $response->json('os', []);
    }

    private function request(): PendingRequest
    {
        return Http::acceptJson()->withToken($this->token)->withHeaders(['User-Agent' => 'BuildPusher'])
            ->connectTimeout(5)->timeout(15);
    }

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

    private function exception(Response $response, string $operation): RuntimeException
    {
        return new RuntimeException("Vultr {$operation} failed with HTTP {$response->status()}.");
    }
}
