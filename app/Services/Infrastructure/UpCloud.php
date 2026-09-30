<?php

declare(strict_types=1);

namespace App\Services\Infrastructure;

use App\Contracts\Infrastructure\ServerProvider;
use App\Data\Infrastructure\CloudServerData;
use App\Data\Infrastructure\CloudSshKeyData;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use RuntimeException;

/**
 * UpCloud. The credential is an API user's "USERNAME:PASSWORD". UpCloud has no stored SSH keys: the public key goes
 * in with each server, so it stands in as the key's reference and there's nothing to delete. Catalogues are returned
 * in the same shape as DigitalOcean's (slug, vcpus, memory in MB, price_monthly) so pricing and right-sizing share code.
 */
class UpCloud implements ServerProvider
{
    /**
     * The API's address.
     *
     * @var string
     */
    private const API = 'https://api.upcloud.com/1.3';

    /**
     * The API user's name.
     *
     * @var string
     */
    private readonly string $username;

    /**
     * The API user's password.
     *
     * @var string
     */
    private readonly string $password;

    /**
     * Create a new UpCloud instance.
     *
     * @param  string  $credential  "USERNAME:PASSWORD"
     */
    public function __construct(string $credential)
    {
        [$this->username, $this->password] = array_pad(explode(':', $credential, 2), 2, '');
    }

    /**
     * Get the provider's name.
     *
     * @return string
     */
    public function name(): string
    {
        return 'UpCloud';
    }

    /**
     * Use the public key itself as the reference: UpCloud takes keys with each server rather than storing them.
     *
     * @param  string  $name
     * @param  string  $publicKey
     * @return CloudSshKeyData
     */
    public function createSshKey(string $name, string $publicKey): CloudSshKeyData
    {
        return new CloudSshKeyData(trim($publicKey), false);
    }

    /**
     * Nothing to delete: UpCloud doesn't store keys.
     *
     * @param  string  $fingerprint
     * @return bool
     */
    public function deleteSshKey(string $fingerprint): bool
    {
        return true;
    }

    /**
     * Create a started server from an Ubuntu template, with a public IPv4 address, the SSH key for root and the
     * provisioning script as cloud-init user data. The disk is the plan's included storage.
     *
     * @param  array<string, mixed>  $parameters  name, region (zone), size (plan), image (template UUID), ssh_keys, user_data
     * @return CloudServerData
     */
    public function createServer(array $parameters): CloudServerData
    {
        $plan = collect($this->sizes())->firstWhere('slug', $parameters['size']);
        $response = $this->request()->post(self::API.'/server', ['server' => [
            'zone' => $parameters['region'], 'title' => $parameters['name'], 'hostname' => $parameters['name'], 'plan' => $parameters['size'],
            'metadata' => 'yes', 'user_data' => (string) ($parameters['user_data'] ?? ''),
            'login_user' => ['username' => 'root', 'create_password' => 'no', 'ssh_keys' => ['ssh_key' => array_values((array) ($parameters['ssh_keys'] ?? []))]],
            'storage_devices' => ['storage_device' => [[
                'action' => 'clone', 'storage' => $parameters['image'], 'title' => $parameters['name'].' disk',
                'size' => is_array($plan) ? max(10, (int) ($plan['disk'] ?? 25)) : 25, 'tier' => 'maxiops',
            ]]],
            'networking' => ['interfaces' => ['interface' => [
                ['type' => 'public', 'ip_addresses' => ['ip_address' => [['family' => 'IPv4']]]],
                ['type' => 'utility', 'ip_addresses' => ['ip_address' => [['family' => 'IPv4']]]],
            ]]],
        ]]);
        if (! $response->successful()) {
            throw $this->exception($response, 'server creation');
        }

        return $this->serverData($response->json('server'));
    }

    /**
     * Look up a server by UUID.
     *
     * @param  int|string  $identifier
     * @return CloudServerData
     */
    public function server(int|string $identifier): CloudServerData
    {
        $response = $this->request()->get(self::API.'/server/'.rawurlencode((string) $identifier));
        if (! $response->successful()) {
            throw $this->exception($response, 'server lookup');
        }

        return $this->serverData($response->json('server'));
    }

    /**
     * Stop the server (UpCloud only deletes stopped servers), wait for it, then delete it with its disks.
     *
     * @param  int|string  $identifier
     * @return bool
     */
    public function deleteServer(int|string $identifier): bool
    {
        $path = self::API.'/server/'.rawurlencode((string) $identifier);
        $stop = $this->request()->post($path.'/stop', ['stop_server' => ['stop_type' => 'hard', 'timeout' => '60']]);
        if ($stop->status() === 404) {
            return true;
        }
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $state = $this->request()->get($path)->json('server.state');
            if ($state === 'stopped' || $state === null) {
                break;
            }
            Sleep::for(3)->seconds();
        }

        return in_array($this->request()->delete($path.'?storages=1&backups=delete')->status(), [204, 404], true);
    }

    /**
     * List the public zones, as DigitalOcean-shaped regions.
     *
     * @return array<array-key, mixed>
     */
    public function regions(): array
    {
        $response = $this->request()->get(self::API.'/zone');
        if (! $response->successful()) {
            throw $this->exception($response, 'zone listing');
        }

        return collect((array) $response->json('zones.zone', []))->filter(fn ($zone): bool => is_array($zone) && ($zone['public'] ?? 'yes') === 'yes')
            ->map(fn (array $zone): array => ['slug' => (string) ($zone['id'] ?? ''), 'name' => (string) ($zone['description'] ?? $zone['id'] ?? ''), 'available' => true])->values()->all();
    }

    /**
     * List the plans with their monthly prices (from the first zone's price list), as DigitalOcean-shaped sizes.
     *
     * @return array<array-key, mixed>
     */
    public function sizes(): array
    {
        $response = $this->request()->get(self::API.'/plan');
        if (! $response->successful()) {
            throw $this->exception($response, 'plan listing');
        }
        $prices = (array) $this->request()->get(self::API.'/price')->json('prices.zone.0', []);

        return collect((array) $response->json('plans.plan', []))->filter(fn ($item): bool => is_array($item))->map(function (array $plan) use ($prices): array {
            $name = (string) ($plan['name'] ?? '');
            $hourly = data_get($prices, 'server_plan_'.$name.'.price');

            return [
                'slug' => $name, 'description' => $name, 'vcpus' => (int) ($plan['core_number'] ?? 0), 'memory' => (int) ($plan['memory_amount'] ?? 0),
                'disk' => (int) ($plan['storage_size'] ?? 25),
                // Prices are in cents an hour; a month is billed as 672 hours at most.
                'price_monthly' => is_numeric($hourly) ? round(((float) $hourly) * 672 / 100, 2) : null,
            ];
        })->values()->all();
    }

    /**
     * List the public Ubuntu templates, as DigitalOcean-shaped images.
     *
     * @return array<array-key, mixed>
     */
    public function images(): array
    {
        $response = $this->request()->get(self::API.'/storage/template');
        if (! $response->successful()) {
            throw $this->exception($response, 'template listing');
        }

        return collect((array) $response->json('storages.storage', []))->filter(fn ($template): bool => is_array($template) && str_contains(strtolower((string) ($template['title'] ?? '')), 'ubuntu'))
            ->map(fn (array $template): array => ['slug' => (string) ($template['uuid'] ?? ''), 'distribution' => 'Ubuntu', 'name' => trim(str_ireplace('Ubuntu Server', '', (string) ($template['title'] ?? '')))])->values()->all();
    }

    /**
     * Prepare an authenticated request.
     *
     * @return PendingRequest
     */
    private function request(): PendingRequest
    {
        return Http::acceptJson()->withBasicAuth($this->username, $this->password)->connectTimeout(5)->timeout(20);
    }

    /**
     * Turn UpCloud's server into the shared shape.
     *
     * @param  mixed  $server
     * @return CloudServerData
     */
    private function serverData(mixed $server): CloudServerData
    {
        if (! is_array($server) || ! isset($server['uuid'])) {
            throw new RuntimeException('UpCloud returned an incomplete server response.');
        }
        $addresses = collect((array) data_get($server, 'ip_addresses.ip_address', []))->filter(fn ($item): bool => is_array($item));
        $publicIp = $addresses->first(fn (array $ip): bool => ($ip['access'] ?? '') === 'public' && ($ip['family'] ?? '') === 'IPv4')['address'] ?? null;
        $privateIp = $addresses->first(fn (array $ip): bool => ($ip['access'] ?? '') === 'utility')['address'] ?? null;
        $status = is_string($server['state'] ?? null) ? $server['state'] : null;

        return new CloudServerData(
            identifier: (string) $server['uuid'],
            name: (string) ($server['hostname'] ?? $server['title'] ?? $server['uuid']),
            region: (string) ($server['zone'] ?? ''),
            size: (string) ($server['plan'] ?? ''),
            image: '',
            publicIp: is_string($publicIp) ? $publicIp : null,
            privateIp: is_string($privateIp) ? $privateIp : null,
            providerStatus: $status,
            readiness: $status === null ? CloudServerData::READINESS_UNKNOWN : ($status === 'started' && is_string($publicIp) ? CloudServerData::READINESS_READY : CloudServerData::READINESS_NOT_READY),
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
        return new RuntimeException("UpCloud {$operation} failed with HTTP {$response->status()}.");
    }
}
